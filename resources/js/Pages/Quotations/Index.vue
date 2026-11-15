<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    quotations: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Object, required: true },
});

const filters = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? null });
watch(filters, () => router.get(route('quotations.index'), Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), { preserveState: true, replace: true }));

const color = { draft: 'gray', sent: 'indigo', accepted: 'green', rejected: 'red', converted: 'amber' };
const columns = [
    { key: 'reference_no', label: 'Quotation' },
    { key: 'customer', label: 'Customer' },
    { key: 'valid_until', label: 'Valid until' },
    { key: 'grand_total', label: 'Total', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Quotations">
        <PageHeader title="Quotations" description="Price quotes for bulk, wedding or corporate orders. Convert to a bill at the counter.">
            <template #actions><ButtonLink :href="route('quotations.create')"><PlusIcon class="h-4 w-4" /> New quotation</ButtonLink></template>
        </PageHeader>
        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-3">
                <SearchInput v-model="filters.search" class="sm:col-span-2" placeholder="Number or customer…" />
                <SelectInput v-model="filters.status" :options="Object.entries(statuses).map(([value, label]) => ({ value, label }))" placeholder="Any status" />
            </div>
            <DataTable :columns="columns" :rows="quotations.data" empty="No quotations yet.">
                <template #reference_no="{ row }">
                    <Link :href="route('quotations.show', row.id)" class="font-mono text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ row.reference_no }}</Link>
                    <p class="text-xs text-gray-500">{{ date(row.date) }} · {{ row.items_count }} items · {{ row.user }}</p>
                </template>
                <template #valid_until="{ row }"><span :class="row.expired ? 'text-red-600' : ''">{{ row.valid_until ? date(row.valid_until) : '—' }}</span></template>
                <template #grand_total="{ row }"><span class="font-medium tabular-nums">{{ money(row.grand_total) }}</span></template>
                <template #status="{ row }">
                    <Badge :color="color[row.status]">{{ statuses[row.status] }}</Badge>
                    <Badge v-if="row.expired" color="red" class="ml-1">Expired</Badge>
                </template>
            </DataTable>
            <Pagination :paginator="quotations" />
        </Card>
    </AppLayout>
</template>
