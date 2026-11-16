<script setup>
defineProps({
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <section class="rounded-lg border border-gray-200 bg-white shadow-xs">
        <header v-if="title || $slots.actions" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-gray-100 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-xs text-gray-500">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div :class="padded ? 'p-5' : ''">
            <slot />
        </div>
    </section>
</template>
