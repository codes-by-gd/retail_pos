<script setup>
defineProps({ type: { type: String, default: 'button' } });
</script>

<template>
    <button :type="type" class="inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium shadow-xs transition focus:outline-hidden focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-indigo-500">
        <slot />
    </button>
</template>
