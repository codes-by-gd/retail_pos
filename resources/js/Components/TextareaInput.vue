<script setup>
const model = defineModel({ type: String, default: '' });
</script>

<template>
    <textarea
        v-model="model"
        rows="3"
        class="block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-indigo-500 focus:ring-indigo-500"
    />
</template>
