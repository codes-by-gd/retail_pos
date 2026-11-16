<script setup>
/**
 * Full-screen camera barcode scanner (teleported overlay — not <dialog>, which breaks hardware scanners).
 *   <CameraScanner v-model:open="scanning" mode="continuous" title="Scan items" :feedback="lastResult" @detected="onCode" />
 * mode "continuous": stays open; the same code is ignored for 1.5 s (so one tag isn't added twice).
 * mode "single": closes after the first code.
 * `feedback` = { text, ok } shown under the frame (e.g. "Added: Slim Fit Shirt — Navy / 40").
 */
import { play } from '@/utils/sounds';
import {
    cameraErrorMessage, cameraProblem, getDetector, listCameras, openCamera, setTorch, startDecoding, stopStream, torchSupported,
} from '@/utils/barcodeScanner';
import { ArrowPathIcon, BoltIcon, BoltSlashIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const open = defineModel('open', { type: Boolean, default: false });
const props = defineProps({
    mode: { type: String, default: 'single' },        // single | continuous
    title: { type: String, default: 'Scan a barcode' },
    hint: { type: String, default: 'Hold the barcode inside the frame' },
    feedback: { type: Object, default: null },        // { text, ok }
    beep: { type: Boolean, default: true },           // false when the parent plays its own sounds (POS)
});
const emit = defineEmits(['detected']);

const video = ref(null);
const status = ref('');
const error = ref('');
const engine = ref('');
const torchOn = ref(false);
const canTorch = ref(false);
const cameras = ref([]);
const count = ref(0);
let stream = null;
let stopLoop = null;
let cameraIndex = -1;
let last = { code: '', at: 0 };

const stop = () => {
    stopLoop?.();
    stopLoop = null;
    stopStream(stream);
    stream = null;
    torchOn.value = false;
};

const start = async (deviceId = null) => {
    error.value = '';
    const problem = cameraProblem();
    if (problem) return (error.value = problem);
    try {
        status.value = 'Starting the camera…';
        const [{ detector, engine: kind }, s] = await Promise.all([getDetector(), openCamera(deviceId)]);
        if (!open.value) return stopStream(s);   // closed while starting
        stream = s;
        engine.value = kind;
        await nextTick();
        video.value.srcObject = stream;
        await video.value.play().catch(() => {});
        canTorch.value = torchSupported(stream);
        if (!cameras.value.length) cameras.value = await listCameras();
        status.value = '';
        stopLoop = startDecoding(video.value, detector, onCode);
    } catch (e) {
        stop();
        status.value = '';
        error.value = cameraErrorMessage(e);
    }
};

const onCode = (code) => {
    const now = Date.now();
    // The same tag held in front of the camera is read many times a second: count it once, and again only
    // after it has been out of view for a moment (so scanning the same item twice is a deliberate move away and back).
    const sameStillInView = code === last.code && now - last.at < 1000;
    last = { code, at: now };
    if (sameStillInView) return;
    count.value++;
    navigator.vibrate?.(60);
    if (props.beep) play('scan');
    emit('detected', code);
    if (props.mode === 'single') close();
};

const close = () => (open.value = false);
const toggleTorch = async () => {
    try {
        await setTorch(stream, !torchOn.value);
        torchOn.value = !torchOn.value;
    } catch {
        canTorch.value = false;
    }
};
const flip = async () => {
    if (cameras.value.length < 2) return;
    cameraIndex = (cameraIndex + 1) % cameras.value.length;
    stop();
    await start(cameras.value[cameraIndex].deviceId);
};

// battery: pause the camera when the tab is hidden, resume when visible again
const onVisibility = () => {
    if (!open.value) return;
    if (document.visibilityState === 'hidden') stop();
    else if (!stream) start();
};

watch(open, (v) => {
    if (v) {
        count.value = 0;
        last = { code: '', at: 0 };
        document.activeElement?.blur?.();      // keep the phone keyboard closed
        document.addEventListener('visibilitychange', onVisibility);
        start();
    } else {
        document.removeEventListener('visibilitychange', onVisibility);
        stop();
        window.dispatchEvent(new Event('pos:refocus'));
    }
}, { immediate: true });
onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibility);
    stop();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[70] flex flex-col bg-black text-white" role="dialog" aria-modal="true" :aria-label="title" @keydown.esc="close">
            <div class="flex items-center gap-3 px-4 py-3">
                <p class="min-w-0 flex-1 truncate font-semibold">{{ title }}</p>
                <button v-if="canTorch" type="button" class="rounded-full p-2 hover:bg-white/10" :aria-pressed="torchOn" :title="torchOn ? 'Torch off' : 'Torch on'" @click="toggleTorch">
                    <component :is="torchOn ? BoltSlashIcon : BoltIcon" class="h-6 w-6" />
                </button>
                <button v-if="cameras.length > 1" type="button" class="rounded-full p-2 hover:bg-white/10" title="Switch camera" @click="flip">
                    <ArrowPathIcon class="h-6 w-6" />
                </button>
                <button type="button" class="rounded-full p-2 hover:bg-white/10" aria-label="Close scanner" @click="close"><XMarkIcon class="h-7 w-7" /></button>
            </div>

            <div class="relative min-h-0 flex-1 overflow-hidden">
                <video ref="video" class="h-full w-full object-cover" playsinline muted autoplay />
                <!-- aiming frame -->
                <div v-if="!error" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="h-40 w-[min(20rem,80vw)] rounded-xl border-4 border-[#fff]/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]">
                        <div class="mx-3 mt-[4.6rem] h-0.5 animate-pulse bg-red-500/80" />
                    </div>
                </div>
                <div v-if="status" class="absolute inset-x-0 top-6 text-center text-sm text-white/90">{{ status }}</div>
                <div v-if="error" class="absolute inset-0 flex items-center justify-center p-6">
                    <div class="max-w-sm rounded-xl bg-[#fff] p-5 text-center text-sm text-[#111827]">
                        <p class="font-semibold">Camera not available</p>
                        <p class="mt-2">{{ error }}</p>
                        <button type="button" class="mt-4 rounded-md bg-[#4f46e5] px-4 py-2 font-medium text-[#fff]" @click="close">OK</button>
                    </div>
                </div>
            </div>

            <div class="space-y-2 px-4 pb-6 pt-3 text-center">
                <p v-if="feedback" class="truncate rounded-md px-3 py-2 text-sm font-medium" :class="feedback.ok === false ? 'bg-[#dc2626]' : 'bg-[#16a34a]'">{{ feedback.text }}</p>
                <p v-else class="text-sm text-white/80">{{ hint }}</p>
                <div class="flex items-center justify-between text-xs text-white/60">
                    <span>{{ mode === 'continuous' ? `${count} scanned` : '' }}</span>
                    <span v-if="engine">{{ engine === 'native' ? 'Built-in scanner' : 'Scanner (WebAssembly)' }}</span>
                </div>
                <button v-if="mode === 'continuous'" type="button" class="w-full rounded-lg bg-[#fff] py-3 font-semibold text-[#111827]" @click="close">Done</button>
            </div>
        </div>
    </Teleport>
</template>
