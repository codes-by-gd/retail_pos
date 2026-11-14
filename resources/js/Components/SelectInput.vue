<script setup>
import { computed } from 'vue';

const model = defineModel({ type: [String, Number, null], default: null });

const props = defineProps({
    /** Array of strings or { value, label } objects */
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
});

const normalized = computed(() =>
    props.options.map((o) => (typeof o === 'object' ? o : { value: o, label: o })),
);
</script>

<template>
    <select
        v-model="model"
        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
    >
        <option v-if="placeholder" :value="null">{{ placeholder }}</option>
        <option v-for="o in normalized" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
</template>
