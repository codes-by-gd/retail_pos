<script setup>
import PageHeader from '@/Components/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArchiveBoxIcon, BanknotesIcon, ChartBarIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';

defineProps({ groups: { type: Array, required: true } });

const icons = { Sales: ChartBarIcon, 'Inventory & purchases': ArchiveBoxIcon, 'Accounts & profit': BanknotesIcon, GST: DocumentTextIcon };
</script>

<template>
    <AppLayout title="Reports">
        <PageHeader title="Reports" description="Every report can be filtered by date and branch, and exported to Excel, CSV or PDF." />

        <div class="space-y-8">
            <section v-for="g in groups" :key="g.group">
                <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500">
                    <component :is="icons[g.group] ?? ChartBarIcon" class="h-4 w-4" /> {{ g.group }}
                </h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Link
                        v-for="r in g.reports"
                        :key="r.key"
                        :href="route('reports.show', r.key)"
                        class="rounded-lg border border-gray-200 bg-white p-4 shadow-xs transition hover:border-indigo-300 hover:shadow-sm"
                    >
                        <p class="font-medium text-gray-900">{{ r.title }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ r.description }}</p>
                    </Link>
                </div>
            </section>
            <p v-if="!groups.length" class="text-sm text-gray-500">You don't have access to any reports.</p>
        </div>
    </AppLayout>
</template>
