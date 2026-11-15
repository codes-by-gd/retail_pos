<script setup>
import { useListFilters } from '@/composables/useListFilters';
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({ orders: { type: Object, required: true }, filters: { type: Object, required: true }, statuses: { type: Object, required: true } });
const { can } = usePermissions();
const filters = useListFilters('purchase-orders.index', { search: props.filters.search ?? '', status: props.filters.status ?? null });
const color = { draft: 'gray', ordered: 'indigo', partially_received: 'amber', received: 'green', cancelled: 'red' };
const columns = [
    { key: 'reference_no', label: 'Order' }, { key: 'supplier', label: 'Supplier' }, { key: 'expected_date', label: 'Expected' },
    { key: 'estimated_total', label: 'Value (excl. GST)', align: 'right' }, { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Purchase orders">
        <PageHeader title="Purchase orders" description="What you've ordered from suppliers and what is still to arrive.">
            <template #actions><ButtonLink v-if="can('purchases.manage')" :href="route('purchase-orders.create')"><PlusIcon class="h-4 w-4" /> New order</ButtonLink></template>
        </PageHeader>
        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-3">
                <SearchInput v-model="filters.search" class="sm:col-span-2" placeholder="Order no. or supplier…" />
                <SelectInput v-model="filters.status" :options="Object.entries(statuses).map(([value, label]) => ({ value, label }))" placeholder="Any status" />
            </div>
            <DataTable :columns="columns" :rows="orders.data" empty="No purchase orders.">
                <template #reference_no="{ row }">
                    <Link :href="route('purchase-orders.show', row.id)" class="font-mono text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ row.reference_no }}</Link>
                    <p class="text-xs text-gray-500">{{ date(row.order_date) }} · {{ row.items_count }} items</p>
                </template>
                <template #expected_date="{ row }">{{ row.expected_date ? date(row.expected_date) : '—' }}</template>
                <template #estimated_total="{ row }"><span class="tabular-nums">{{ money(row.estimated_total) }}</span></template>
                <template #status="{ row }"><Badge :color="color[row.status]">{{ statuses[row.status] }}</Badge></template>
            </DataTable>
            <Pagination :paginator="orders" />
        </Card>
    </AppLayout>
</template>
