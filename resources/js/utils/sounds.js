/**
 * Small synthesized sounds (Web Audio, no files). Each area has its own on/off switch stored per device:
 *   play('scan', 'pos')    — POS sounds, switch key 'pos'
 *   play('alert', 'alerts') — notification chime, switch key 'alerts'
 */
import { ref } from 'vue';

let ctx = null;
const KEY = (area) => `sound-${area}`;

const read = (area, fallback = true) => {
    try {
        const v = localStorage.getItem(KEY(area));
        return v === null ? fallback : v === '1';
    } catch {
        return fallback;
    }
};
const switches = {};
/** Reactive on/off for an area (default `fallback` until the user chooses). */
export function soundSwitch(area, fallback = true) {
    switches[area] ??= ref(read(area, fallback));
    return switches[area];
}
export function setSound(area, on) {
    soundSwitch(area).value = on;
    try {
        localStorage.setItem(KEY(area), on ? '1' : '0');
    } catch {
        /* ignore */
    }
    if (on) play('toggle', area);
}

// [frequency Hz, start s, length s, wave, volume]
const SOUNDS = {
    scan: [[1320, 0, 0.07, 'square', 0.05]],                                            // item scanned / added
    add: [[880, 0, 0.05, 'sine', 0.08]],                                                // picked from search
    remove: [[520, 0, 0.06, 'sine', 0.07], [390, 0.06, 0.08, 'sine', 0.06]],            // line removed
    notfound: [[220, 0, 0.12, 'sawtooth', 0.05], [180, 0.15, 0.18, 'sawtooth', 0.05]], // unknown barcode
    warning: [[440, 0, 0.1, 'triangle', 0.08], [440, 0.14, 0.1, 'triangle', 0.08]],    // no stock / blocked
    error: [[300, 0, 0.15, 'square', 0.04], [200, 0.17, 0.25, 'square', 0.04]],         // payment failed
    payment: [[988, 0, 0.06, 'sine', 0.08], [1319, 0.07, 0.09, 'sine', 0.08]],          // payment line added
    sale: [[784, 0, 0.1, 'sine', 0.09], [988, 0.1, 0.1, 'sine', 0.09], [1319, 0.2, 0.22, 'sine', 0.09]], // sale complete
    hold: [[660, 0, 0.07, 'sine', 0.07], [495, 0.08, 0.1, 'sine', 0.07]],
    alert: [[1047, 0, 0.12, 'sine', 0.07], [1397, 0.13, 0.2, 'sine', 0.06]],             // notification chime
    toggle: [[1046, 0, 0.06, 'sine', 0.06]],
};

export function play(name, area = 'pos') {
    if (!soundSwitch(area).value) return;
    try {
        ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        const now = ctx.currentTime;
        for (const [freq, start, len, type, vol] of SOUNDS[name] ?? []) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(vol, now + start);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + start + len);   // soft end, no clicks
            osc.connect(gain).connect(ctx.destination);
            osc.start(now + start);
            osc.stop(now + start + len + 0.02);
        }
    } catch {
        /* audio not available */
    }
}
