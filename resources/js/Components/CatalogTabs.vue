<script setup>
import { Link } from '@inertiajs/vue3';

const tabs = [
    { label: 'Categories', route: 'categories.index' },
    { label: 'Brands', route: 'brands.index' },
    { label: 'Sizes & Colours', route: 'attributes.index' },
    { label: 'Units', route: 'units.index' },
    { label: 'GST rates', route: 'tax-rates.index' },
];
</script>

<template>
    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-gray-200">
        <Link
            v-for="tab in tabs"
            :key="tab.route"
            :href="route(tab.route)"
            class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium"
            :class="route().current(tab.route) ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
