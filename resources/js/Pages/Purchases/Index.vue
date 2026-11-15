<script setup>
import DateRangeFilter from '@/Components/DateRangeFilter.vue';
import { useListFilters } from '@/composables/useListFilters';
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/utils/format';
import { TruckIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({ purchases: { type: Object, required: true }, filters: { type: Object, required: true }, totals: { type: Object, required: true } });
const { can } = usePermissions();
const filters = useListFilters('purchases.index', { search: props.filters.search ?? '', payment_status: props.filters.payment_status ?? null, from: props.filters.from ?? '', to: props.filters.to ?? '' });
const color = { paid: 'green', partial: 'amber', due: 'red' };
const columns = [
    { key: 'reference_no', label: 'Purchase' }, { key: 'supplier', label: 'Supplier' },
    { key: 'grand_total', label: 'Bill total', align: 'right' }, { key: 'due_amount', label: 'Due', align: 'right' }, { key: 'payment_status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Purchases">
        <PageHeader title="Purchases" :description="`${totals.bills} bills · ${money(totals.total)} · ${money(totals.due)} still to pay`">
            <template #actions>
                <ButtonLink :href="route('purchase-orders.index')" variant="secondary">Purchase orders</ButtonLink>
                <ButtonLink v-if="can('purchases.manage')" :href="route('purchases.create')"><TruckIcon class="h-4 w-4" /> Receive goods</ButtonLink>
            </template>
        </PageHeader>
        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 md:grid-cols-5">
                <SearchInput v-model="filters.search" class="md:col-span-2" placeholder="Number, supplier bill no. or supplier…" />
                <SelectInput v-model="filters.payment_status" :options="[{ value: 'due', label: 'Unpaid' }, { value: 'partial', label: 'Part paid' }, { value: 'paid', label: 'Paid' }]" placeholder="Any payment" />
                <DateRangeFilter v-model:from="filters.from" v-model:to="filters.to" />
            </div>
            <DataTable :columns="columns" :rows="purchases.data" empty="No purchases yet.">
                <template #reference_no="{ row }">
                    <Link :href="route('purchases.show', row.id)" class="font-mono text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ row.reference_no }}</Link>
                    <p class="text-xs text-gray-500">{{ date(row.date) }} · bill {{ row.bill ?? '—' }} · {{ row.items_count }} items</p>
                </template>
                <template #grand_total="{ row }"><span class="tabular-nums">{{ money(row.grand_total) }}</span></template>
                <template #due_amount="{ row }"><span class="tabular-nums" :class="row.due_amount > 0 ? 'font-medium text-amber-700' : 'text-gray-400'">{{ row.due_amount > 0 ? money(row.due_amount) : '—' }}</span></template>
                <template #payment_status="{ row }"><Badge :color="color[row.payment_status]">{{ { paid: 'Paid', partial: 'Part paid', due: 'Unpaid' }[row.payment_status] }}</Badge></template>
            </DataTable>
            <Pagination :paginator="purchases" />
        </Card>
    </AppLayout>
</template>
