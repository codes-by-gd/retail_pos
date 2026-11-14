<script setup>
import InputError from '@/Components/InputError.vue';

defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    required: { type: Boolean, default: false },
    for: { type: String, default: undefined },
});
</script>

<template>
    <div>
        <label v-if="label" :for="$props.for" class="mb-1 block text-sm font-medium text-gray-700">
            {{ label }}<span v-if="required" class="text-red-500"> *</span>
        </label>
        <slot />
        <p v-if="hint && !error" class="mt-1 text-xs text-gray-500">{{ hint }}</p>
        <InputError :message="error" class="mt-1" />
    </div>
</template>
