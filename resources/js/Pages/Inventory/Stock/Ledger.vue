<script setup>
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money, qty } from '@/utils/format';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    movements: { type: Object, required: true },
    filters: { type: Object, required: true },
    variant: { type: Object, default: null },
    types: { type: Array, required: true },
});

const filters = reactive({
    variant_id: props.filters.variant_id ?? null,
    type: props.filters.type ?? null,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
watch(filters, () => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v));
    router.get(route('stock.ledger'), query, { preserveState: true, preserveScroll: true, replace: true });
});

const columns = [
    { key: 'date', label: 'Date' },
    ...(props.variant ? [] : [{ key: 'item', label: 'Item' }]),
    { key: 'type_label', label: 'Type' },
    { key: 'reference', label: 'Reference' },
    { key: 'quantity', label: 'Qty', align: 'right' },
    { key: 'unit_cost', label: 'Unit cost', align: 'right' },
    { key: 'balance_after', label: 'Balance', align: 'right' },
    { key: 'user', label: 'By' },
];
</script>

<template>
    <AppLayout title="Stock ledger">
        <PageHeader
            title="Stock ledger"
            :description="`Every stock movement at ${$page.props.branch?.current?.name ?? 'this branch'}, newest first.`"
        />

        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-4">
                <div v-if="variant" class="flex items-center justify-between gap-2 rounded-md bg-indigo-50 px-3 py-2 text-sm sm:col-span-1">
                    <span class="truncate font-medium text-indigo-800" :title="variant.name">{{ variant.name }}</span>
                    <button class="text-indigo-500 hover:text-indigo-700" title="Show all items" @click="filters.variant_id = null">
                        <XMarkIcon class="h-4 w-4" />
                    </button>
                </div>
                <SelectInput v-model="filters.type" :options="types" placeholder="All movement types" />
                <TextInput v-model="filters.from" type="date" aria-label="From date" />
                <TextInput v-model="filters.to" type="date" aria-label="To date" />
            </div>

            <DataTable :columns="columns" :rows="movements.data" empty="No stock movements.">
                <template #date="{ row }"><span class="whitespace-nowrap text-xs text-gray-600">{{ dateTime(row.date) }}</span></template>
                <template #item="{ row }">
                    <button class="text-left font-medium text-gray-900 hover:text-indigo-700" @click="filters.variant_id = row.variant_id">{{ row.item }}</button>
                    <p class="font-mono text-xs text-gray-500">{{ row.sku }}</p>
                </template>
                <template #type_label="{ row }">
                    {{ row.type_label }}
                    <p v-if="row.note && row.note !== row.type_label" class="text-xs text-gray-500">{{ row.note }}</p>
                </template>
                <template #reference="{ row }">
                    <Link v-if="row.reference_url" :href="row.reference_url" class="font-mono text-xs text-indigo-600 hover:text-indigo-800">{{ row.reference }}</Link>
                    <span v-else class="text-gray-400">—</span>
                </template>
                <template #quantity="{ row }">
                    <span class="font-medium tabular-nums" :class="row.quantity < 0 ? 'text-red-600' : 'text-green-700'">
                        {{ row.quantity > 0 ? '+' : '' }}{{ qty(row.quantity) }}
                    </span>
                </template>
                <template #unit_cost="{ row }">{{ money(row.unit_cost) }}</template>
                <template #balance_after="{ row }"><span class="tabular-nums">{{ qty(row.balance_after) }}</span></template>
                <template #user="{ row }"><span class="text-xs text-gray-600">{{ row.user ?? '—' }}</span></template>
            </DataTable>

            <Pagination :paginator="movements" />
        </Card>
    </AppLayout>
</template>
