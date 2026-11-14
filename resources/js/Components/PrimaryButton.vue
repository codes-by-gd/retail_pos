<script setup>
defineProps({ type: { type: String, default: 'submit' } });
</script>

<template>
    <button :type="type" class="inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 bg-indigo-600 text-white hover:bg-indigo-500 focus:ring-indigo-500">
        <slot />
    </button>
</template>
