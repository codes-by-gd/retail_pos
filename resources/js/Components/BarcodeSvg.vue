<script setup>
import JsBarcode from 'jsbarcode';
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    value: { type: String, required: true },
    height: { type: Number, default: 40 },
    width: { type: Number, default: 1.5 },
    fontSize: { type: Number, default: 12 },
    displayValue: { type: Boolean, default: true },
});

const svg = ref(null);
const failed = ref(false);

const render = () => {
    if (!svg.value || !props.value) return;
    // EAN-13 when the value is a valid 13-digit code, Code128 otherwise.
    const format = /^\d{13}$/.test(props.value) ? 'EAN13' : 'CODE128';
    try {
        JsBarcode(svg.value, props.value, {
            format,
            height: props.height,
            width: props.width,
            fontSize: props.fontSize,
            displayValue: props.displayValue,
            margin: 0,
        });
        failed.value = false;
    } catch {
        JsBarcode(svg.value, props.value, { format: 'CODE128', height: props.height, width: props.width, fontSize: props.fontSize, displayValue: props.displayValue, margin: 0 });
    }
};

onMounted(render);
watch(() => [props.value, props.height, props.width, props.fontSize, props.displayValue], render);
</script>

<template>
    <svg ref="svg" class="max-w-full" />
</template>
