<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    sales: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    cashiers: { type: Array, default: () => [] },
});

const filters = reactive({
    search: props.filters.search ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    user_id: props.filters.user_id ? Number(props.filters.user_id) : null,
    payment_status: props.filters.payment_status ?? null,
    status: props.filters.status ?? null,
});
watch(filters, () => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v));
    router.get(route('sales.index'), query, { preserveState: true, preserveScroll: true, replace: true });
});

const statusColor = { paid: 'green', partial: 'amber', due: 'red' };
const columns = [
    { key: 'invoice_no', label: 'Invoice' },
    { key: 'customer', label: 'Customer' },
    { key: 'items_count', label: 'Items', align: 'right' },
    { key: 'methods', label: 'Paid by' },
    { key: 'grand_total', label: 'Total', align: 'right' },
    { key: 'payment_status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Sales">
        <PageHeader title="Sales" :description="`${summary.bills} bills · ${money(summary.total)} (matching filters)`" />
        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 md:grid-cols-3 xl:grid-cols-6">
                <SearchInput v-model="filters.search" class="md:col-span-1 xl:col-span-2" placeholder="Invoice no., customer or phone…" />
                <TextInput v-model="filters.from" type="date" aria-label="From" />
                <TextInput v-model="filters.to" type="date" aria-label="To" />
                <SelectInput v-model="filters.user_id" :options="cashiers" placeholder="All cashiers" />
                <SelectInput
                    v-model="filters.payment_status"
                    :options="[{ value: 'due', label: 'Unpaid' }, { value: 'partial', label: 'Part paid' }, { value: 'paid', label: 'Paid' }]"
                    placeholder="Any payment"
                />
                <SelectInput v-model="filters.status" :options="[{ value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' }]" placeholder="Any status" />
            </div>
            <DataTable :columns="columns" :rows="sales.data" empty="No sales found.">
                <template #invoice_no="{ row }">
                    <Link :href="route('sales.show', row.id)" class="font-mono text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ row.invoice_no }}</Link>
                    <p class="text-xs text-gray-500">{{ dateTime(row.sold_at) }} · {{ row.cashier }}</p>
                </template>
                <template #customer="{ row }">{{ row.customer }}<p v-if="row.phone" class="text-xs text-gray-500">{{ row.phone }}</p></template>
                <template #methods="{ row }"><span class="text-xs">{{ row.methods.join(', ') }}</span></template>
                <template #grand_total="{ row }">
                    <span class="font-medium tabular-nums" :class="row.status === 'cancelled' ? 'text-gray-400 line-through' : ''">{{ money(row.grand_total) }}</span>
                    <p v-if="row.due_amount > 0 && row.status !== 'cancelled'" class="text-xs text-amber-700">due {{ money(row.due_amount) }}</p>
                </template>
                <template #payment_status="{ row }">
                    <Badge v-if="row.status === 'cancelled'" color="gray">Cancelled</Badge>
                    <Badge v-else :color="statusColor[row.payment_status]">{{ row.payment_status === 'paid' ? 'Paid' : row.payment_status === 'partial' ? 'Part paid' : 'Due' }}</Badge>
                </template>
            </DataTable>
            <Pagination :paginator="sales" />
        </Card>
    </AppLayout>
</template>
