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
import { money, number, qty } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    rows: { type: Object, required: true },
    filters: { type: Object, required: true },
    totals: { type: Object, required: true },
    categories: { type: Array, required: true },
});

const { can } = usePermissions();

const filters = useListFilters('stock.index', {
    search: props.filters.search ?? '',
    category_id: props.filters.category_id ? Number(props.filters.category_id) : null,
    level: props.filters.level ?? null,
});

const status = (r) => {
    if (r.quantity <= 0) return { color: 'red', label: 'Out of stock' };
    if (r.quantity <= r.reorder_level) return { color: 'amber', label: 'Low' };
    return { color: 'green', label: 'In stock' };
};

const columns = [
    { key: 'name', label: 'Item' },
    { key: 'quantity', label: 'On hand', align: 'right' },
    { key: 'reorder_level', label: 'Reorder at', align: 'right' },
    { key: 'avg_cost', label: 'Avg cost', align: 'right' },
    { key: 'value', label: 'Stock value', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Stock">
        <PageHeader title="Stock on hand" :description="`Current quantities at ${$page.props.branch?.current?.name ?? 'this branch'}.`">
            <template #actions>
                <ButtonLink :href="route('stock.ledger')" variant="secondary">Stock ledger</ButtonLink>
                <ButtonLink v-if="can('stock.transfer')" :href="route('stock-transfers.index')" variant="secondary">Transfers</ButtonLink>
                <ButtonLink v-if="can('stock.adjust')" :href="route('stock-adjustments.create')">Adjust stock</ButtonLink>
            </template>
        </PageHeader>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">Items</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number(totals.items) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">Stock value (at cost)</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ money(totals.value) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">Retail value</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ money(totals.retail_value) }}</p>
            </div>
        </div>

        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-3">
                <SearchInput v-model="filters.search" placeholder="Name, SKU or barcode…" />
                <SelectInput v-model="filters.category_id" :options="categories" placeholder="All categories" />
                <SelectInput
                    v-model="filters.level"
                    :options="[
                        { value: 'in', label: 'In stock' },
                        { value: 'low', label: 'Low or out of stock' },
                        { value: 'out', label: 'Out of stock' },
                    ]"
                    placeholder="Any level"
                />
            </div>

            <DataTable :columns="columns" :rows="rows.data" empty="No stock-tracked items found.">
                <template #name="{ row }">
                    <Link :href="route('products.show', row.product_id)" class="font-medium text-gray-900 hover:text-indigo-700">{{ row.name }}</Link>
                    <p class="font-mono text-xs text-gray-500">{{ row.sku }}</p>
                </template>
                <template #quantity="{ row }">
                    <span class="font-medium tabular-nums" :class="row.quantity <= 0 ? 'text-red-600' : ''">{{ qty(row.quantity) }} {{ row.unit }}</span>
                </template>
                <template #reorder_level="{ row }"><span class="tabular-nums text-gray-500">{{ qty(row.reorder_level) }}</span></template>
                <template #avg_cost="{ row }">{{ money(row.avg_cost) }}</template>
                <template #value="{ row }"><span class="tabular-nums">{{ money(row.value) }}</span></template>
                <template #status="{ row }"><Badge :color="status(row).color">{{ status(row).label }}</Badge></template>
                <template #actions="{ row }">
                    <Link :href="route('stock.ledger', { variant_id: row.id })" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">History</Link>
                </template>
            </DataTable>

            <Pagination :paginator="rows" />
        </Card>
    </AppLayout>
</template>
