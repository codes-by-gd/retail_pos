<script setup>
import { Link } from '@inertiajs/vue3';

/** Pass a Laravel paginator (LengthAwarePaginator serialised by Inertia). */
defineProps({
    paginator: { type: Object, required: true },
});
</script>

<template>
    <div
        v-if="paginator.total > 0"
        class="flex flex-col items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 text-sm sm:flex-row"
    >
        <p class="text-gray-500">
            Showing <span class="font-medium text-gray-700">{{ paginator.from }}</span>–<span class="font-medium text-gray-700">{{ paginator.to }}</span>
            of <span class="font-medium text-gray-700">{{ paginator.total }}</span>
        </p>
        <nav v-if="paginator.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="(link, i) in paginator.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="rounded-md px-3 py-1.5"
                    :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                    v-html="link.label"
                />
                <span v-else class="rounded-md px-3 py-1.5 text-gray-300" v-html="link.label" />
            </template>
        </nav>
    </div>
</template>
