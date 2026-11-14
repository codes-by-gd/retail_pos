<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    variant: { type: String, default: 'primary' }, // primary | secondary
});
</script>

<template>
    <Link
        :href="href"
        class="inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium shadow-sm transition"
        :class="variant === 'primary' ? 'bg-indigo-600 text-white hover:bg-indigo-500' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
    >
        <slot />
    </Link>
</template>
