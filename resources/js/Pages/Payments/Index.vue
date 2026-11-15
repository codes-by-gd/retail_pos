<script setup>
import DateRangeFilter from '@/Components/DateRangeFilter.vue';
import { useListFilters } from '@/composables/useListFilters';
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { printUrl } from '@/Pos/helpers';
import { date, money } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({ payments: { type: Object, required: true }, filters: { type: Object, required: true }, totals: { type: Object, required: true }, methods: { type: Array, required: true } });
const filters = useListFilters('payments.index', { search: props.filters.search ?? '', direction: props.filters.direction ?? null, method: props.filters.method ?? null, from: props.filters.from ?? '', to: props.filters.to ?? '' });
const columns = [
    { key: 'reference_no', label: 'Receipt / voucher' }, { key: 'contact', label: 'From / to' }, { key: 'method', label: 'Mode' },
    { key: 'amount', label: 'Amount', align: 'right' }, { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Payments">
        <PageHeader title="Payments" :description="`Received ${money(totals.received)} · paid ${money(totals.paid)} (matching filters)`" />
        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 md:grid-cols-5">
                <SearchInput v-model="filters.search" placeholder="Number, ref or name…" />
                <SelectInput v-model="filters.direction" :options="[{ value: 'in', label: 'Received from customers' }, { value: 'out', label: 'Paid to suppliers' }]" placeholder="In & out" />
                <SelectInput v-model="filters.method" :options="methods" placeholder="Any mode" />
                <DateRangeFilter v-model:from="filters.from" v-model:to="filters.to" />
            </div>
            <DataTable :columns="columns" :rows="payments.data" empty="No payments recorded.">
                <template #reference_no="{ row }">
                    <span class="font-mono text-sm">{{ row.reference_no }}</span>
                    <p class="text-xs text-gray-500">{{ date(row.date) }} · {{ row.user }}</p>
                </template>
                <template #contact="{ row }">
                    <Link :href="route(`${row.contact_kind}.show`, row.contact_id)" class="text-indigo-600 hover:underline">{{ row.contact }}</Link>
                    <p v-if="row.unallocated > 0" class="text-xs text-gray-500">{{ money(row.unallocated) }} on account</p>
                </template>
                <template #method="{ row }">{{ row.method }}<p v-if="row.reference" class="text-xs text-gray-500">{{ row.reference }}</p></template>
                <template #amount="{ row }">
                    <Badge :color="row.direction === 'in' ? 'green' : 'amber'" class="mr-2">{{ row.direction === 'in' ? 'In' : 'Out' }}</Badge>
                    <span class="font-medium tabular-nums">{{ money(row.amount) }}</span>
                </template>
                <template #actions="{ row }"><button type="button" class="text-sm text-indigo-600 hover:text-indigo-800" @click="printUrl(route('payments.print', row.id))">Print</button></template>
            </DataTable>
            <Pagination :paginator="payments" />
        </Card>
    </AppLayout>
</template>
