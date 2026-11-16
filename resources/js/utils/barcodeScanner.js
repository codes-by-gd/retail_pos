/**
 * Camera barcode scanning for phones/tablets (and webcams).
 *  - Native BarcodeDetector where the browser has it (Chrome/Edge on Android, desktop Chromium).
 *  - Otherwise the `barcode-detector` ponyfill (ZXing compiled to WebAssembly) — iPhone/iPad Safari, Firefox.
 *    It is lazy-loaded only when a camera opens, and its .wasm is served from our own server (no CDN).
 * Camera access needs HTTPS (or localhost).
 */

export const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'];

/** Why camera scanning can't work here, or null when it can. */
export function cameraProblem() {
    if (typeof window === 'undefined') return 'No browser.';
    if (!window.isSecureContext) return 'The camera only works on a secure (https://) address. Ask your administrator to enable HTTPS.';
    if (!navigator.mediaDevices?.getUserMedia) return 'This browser cannot use the camera.';
    return null;
}

export const cameraSupported = () => cameraProblem() === null;

let detectorPromise = null;

/** A BarcodeDetector instance (native or WASM). Cached; ?scanner=wasm forces the fallback (testing). */
export function getDetector() {
    detectorPromise ??= (async () => {
        const forceWasm = new URLSearchParams(window.location.search).get('scanner') === 'wasm';
        if (!forceWasm && 'BarcodeDetector' in window) {
            try {
                const supported = await window.BarcodeDetector.getSupportedFormats();
                const formats = FORMATS.filter((f) => supported.includes(f));
                if (formats.includes('ean_13') && formats.includes('code_128')) {
                    return { detector: new window.BarcodeDetector({ formats }), engine: 'native' };
                }
            } catch {
                /* fall through to WASM */
            }
        }
        const [{ BarcodeDetector, prepareZXingModule }, { default: wasmUrl }] = await Promise.all([
            import('barcode-detector/ponyfill'),
            import('zxing-wasm/reader/zxing_reader.wasm?url'),
        ]);
        prepareZXingModule({
            overrides: { locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path) },
        });
        return { detector: new BarcodeDetector({ formats: FORMATS }), engine: 'wasm' };
    })();
    detectorPromise.catch(() => (detectorPromise = null));
    return detectorPromise;
}

/** Back camera, HD, continuous focus where supported. */
export async function openCamera(deviceId = null) {
    const video = {
        width: { ideal: 1280 },
        height: { ideal: 720 },
        ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }),
        advanced: [{ focusMode: 'continuous' }],
    };
    return navigator.mediaDevices.getUserMedia({ audio: false, video });
}

export function stopStream(stream) {
    stream?.getTracks().forEach((t) => t.stop());
}

export async function listCameras() {
    try {
        return (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
    } catch {
        return [];
    }
}

export function torchSupported(stream) {
    const track = stream?.getVideoTracks()[0];
    return !!track?.getCapabilities?.().torch;
}

export async function setTorch(stream, on) {
    const track = stream?.getVideoTracks()[0];
    await track?.applyConstraints({ advanced: [{ torch: on }] });
}

/** Friendly message for getUserMedia errors. */
export function cameraErrorMessage(e) {
    switch (e?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Camera permission was blocked. Allow the camera for this site (tap the lock icon in the address bar → Permissions → Camera), then try again.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'No camera was found on this device.';
        case 'NotReadableError':
        case 'AbortError':
            return 'The camera is busy in another app. Close it and try again.';
        default:
            return e?.message || 'The camera could not be started.';
    }
}

/**
 * Decode loop: calls onCode(rawValue, format) for every barcode seen, ~8 times a second.
 * Returns a stop() function.
 */
export function startDecoding(video, detector, onCode, { intervalMs = 120 } = {}) {
    let stopped = false;
    let busy = false;
    const tick = async () => {
        if (stopped) return;
        if (!busy && video.readyState >= 2 && video.videoWidth) {
            busy = true;
            try {
                const codes = await detector.detect(video);
                for (const c of codes) if (c.rawValue) onCode(c.rawValue.trim(), c.format);
            } catch {
                /* a frame failed to decode — keep going */
            }
            busy = false;
        }
        if (!stopped) setTimeout(tick, intervalMs);
    };
    tick();
    return () => (stopped = true);
}
