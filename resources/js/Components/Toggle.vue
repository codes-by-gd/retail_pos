<script setup>
const model = defineModel({ type: Boolean, default: false });

defineProps({
    label: { type: String, default: '' },
    description: { type: String, default: '' },
});
</script>

<template>
    <label class="flex cursor-pointer items-start gap-3">
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            :class="model ? 'bg-indigo-600' : 'bg-gray-200'"
            @click="model = !model"
        >
            <span
                class="inline-block h-4 w-4 translate-y-0.5 rounded-full bg-[#fff] shadow transition-transform"
                :class="model ? 'translate-x-[18px]' : 'translate-x-0.5'"
            />
        </button>
        <span v-if="label || description" class="text-sm">
            <span class="block font-medium text-gray-700">{{ label }}</span>
            <span v-if="description" class="block text-xs text-gray-500">{{ description }}</span>
        </span>
    </label>
</template>
