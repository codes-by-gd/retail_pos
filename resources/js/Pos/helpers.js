/** Print a server-rendered page (receipt / invoice / credit note) through a hidden iframe. */
export function printUrl(url, format) {
    const u = new URL(url, window.location.origin);
    u.searchParams.set('autoprint', '1');
    if (format) u.searchParams.set('format', format);

    document.getElementById('pos-print-frame')?.remove();
    const frame = document.createElement('iframe');
    frame.id = 'pos-print-frame';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    frame.src = u.toString();
    // The frame grabs focus to print; give it back so the next barcode scan reaches the POS search box.
    frame.addEventListener('load', () => {
        const done = () => {
            frame.remove();
            window.focus();
            window.dispatchEvent(new Event('pos:refocus'));
        };
        try {
            frame.contentWindow.addEventListener('afterprint', () => setTimeout(done, 50));
        } catch {
            /* cross-origin — not expected */
        }
        setTimeout(() => {
            window.focus();
            window.dispatchEvent(new Event('pos:refocus'));
        }, 1500);
    });
    document.body.appendChild(frame);
}

/** First human-readable message from an axios error (422 validation, 409, 403…). */
export function apiError(e) {
    const data = e?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat()[0];
    if (data?.message) return data.message;
    if (e?.response?.status === 419) return 'Your session expired. Reload the page.';
    return 'Something went wrong. Please try again.';
}

let audio;
/** Short beep for barcode scans. */
export function beep(ok = true) {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        osc.frequency.value = ok ? 1200 : 300;
        gain.gain.value = 0.08;
        osc.connect(gain).connect(audio.destination);
        osc.start();
        osc.stop(audio.currentTime + (ok ? 0.07 : 0.25));
    } catch {
        /* audio not available */
    }
}
