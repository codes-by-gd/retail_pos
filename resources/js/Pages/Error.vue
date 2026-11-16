<script setup>
/** Production error page (403 / 404 / 500 / 503) — rendered by the exception handler in bootstrap/app.php. */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    status: { type: Number, required: true },
    message: { type: String, default: null },
});

const back = () => window.history.back();
const text = computed(() => ({
    403: ['Not allowed', props.message || "Your role doesn't have access to this page. Ask an administrator if you need it."],
    404: ['Page not found', 'The page or record you are looking for does not exist (or was removed).'],
    500: ['Something went wrong', 'The error has been logged. Please try again; if it keeps happening, tell your administrator what you were doing.'],
    503: ['Back soon', 'The system is being updated. Please try again in a minute.'],
})[props.status] ?? ['Error', '']);
</script>

<template>
    <Head :title="text[0]" />
    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <p class="text-sm font-semibold text-indigo-600">{{ status }}</p>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ text[0] }}</h1>
            <p class="mt-3 text-sm text-gray-600">{{ text[1] }}</p>
            <div class="mt-6 flex justify-center gap-3">
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" @click="back">Go back</button>
                <Link :href="route('dashboard')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Dashboard</Link>
            </div>
        </div>
    </div>
</template>
