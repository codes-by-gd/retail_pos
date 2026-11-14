<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: { type: String, default: 'gray' }, // gray | green | red | amber | indigo
});

const classes = computed(
    () =>
        ({
            gray: 'bg-gray-100 text-gray-700',
            green: 'bg-green-50 text-green-700 ring-green-600/20',
            red: 'bg-red-50 text-red-700 ring-red-600/20',
            amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
            indigo: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
        })[props.color],
);
</script>

<template>
    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-transparent" :class="classes">
        <slot />
    </span>
</template>
