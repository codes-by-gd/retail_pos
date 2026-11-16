<script setup>
/**
 * Camera icon button that opens the CameraScanner. Put it next to any barcode input:
 *   <ScanButton mode="continuous" title="Scan items" :feedback="last" @detected="onCode" />
 */
import CameraScanner from '@/Components/CameraScanner.vue';
import { CameraIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

defineProps({
    mode: { type: String, default: 'single' },
    title: { type: String, default: 'Scan a barcode' },
    hint: { type: String, default: undefined },
    feedback: { type: Object, default: null },
    beep: { type: Boolean, default: true },
    label: { type: String, default: '' },   // text next to the icon (optional)
});
const emit = defineEmits(['detected', 'open', 'close']);
const open = ref(false);

defineExpose({ open: () => (open.value = true), close: () => (open.value = false), isOpen: open });
</script>

<template>
    <button
        type="button"
        class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 text-gray-600 shadow-xs hover:border-indigo-400 hover:text-indigo-700"
        :title="title"
        :aria-label="title"
        @click="(open = true), emit('open')"
    >
        <CameraIcon class="h-5 w-5" />
        <span v-if="label" class="text-sm">{{ label }}</span>
    </button>
    <CameraScanner
        v-model:open="open"
        :mode="mode"
        :title="title"
        :hint="hint"
        :feedback="feedback"
        :beep="beep"
        @detected="(c) => emit('detected', c)"
        @update:open="(v) => !v && emit('close')"
    />
</template>
