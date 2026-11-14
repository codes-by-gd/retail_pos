<script setup>
import { CheckCircleIcon, ExclamationCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const page = usePage();
const toasts = ref([]);
let nextId = 1;

const push = (type, message) => {
    const id = nextId++;
    toasts.value.push({ id, type, message });
    setTimeout(() => dismiss(id), type === 'error' ? 6000 : 3500);
};
const dismiss = (id) => (toasts.value = toasts.value.filter((t) => t.id !== id));

// A new flash object arrives with every Inertia response.
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) push('success', flash.success);
        if (flash?.error) push('error', flash.error);
    },
    { immediate: true },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4 sm:items-end">
        <TransitionGroup
            enter-active-class="transition duration-200"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-for="t in toasts"
                :key="t.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white p-3 shadow-lg"
                :class="t.type === 'error' ? 'border-red-200' : 'border-green-200'"
                role="status"
            >
                <ExclamationCircleIcon v-if="t.type === 'error'" class="h-5 w-5 shrink-0 text-red-500" />
                <CheckCircleIcon v-else class="h-5 w-5 shrink-0 text-green-500" />
                <p class="flex-1 text-sm text-gray-800">{{ t.message }}</p>
                <button class="text-gray-400 hover:text-gray-600" @click="dismiss(t.id)">
                    <XMarkIcon class="h-4 w-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
