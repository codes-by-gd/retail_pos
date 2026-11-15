<script setup>
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, dateTime, money, qty } from '@/utils/format';
import { computed } from 'vue';

const props = defineProps({ adjustment: { type: Object, required: true } });

const total = computed(() => props.adjustment.items.reduce((s, i) => s + i.value, 0));
const columns = [
    { key: 'name', label: 'Item' },
    { key: 'quantity', label: 'Change', align: 'right' },
    { key: 'unit_cost', label: 'Unit cost', align: 'right' },
    { key: 'value', label: 'Value', align: 'right' },
];
</script>

<template>
    <AppLayout :title="adjustment.reference_no">
        <PageHeader :title="`Adjustment ${adjustment.reference_no}`" />

        <Card class="mb-6">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-500">Date</dt><dd class="font-medium">{{ date(adjustment.date) }}</dd></div>
                <div><dt class="text-gray-500">Reason</dt><dd class="font-medium">{{ adjustment.reason }}</dd></div>
                <div><dt class="text-gray-500">Branch</dt><dd class="font-medium">{{ adjustment.branch }}</dd></div>
                <div><dt class="text-gray-500">Recorded by</dt><dd class="font-medium">{{ adjustment.user }} · {{ dateTime(adjustment.created_at) }}</dd></div>
                <div v-if="adjustment.note" class="sm:col-span-4"><dt class="text-gray-500">Note</dt><dd>{{ adjustment.note }}</dd></div>
            </dl>
        </Card>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="adjustment.items">
                <template #name="{ row }">
                    <p class="font-medium text-gray-900">{{ row.name }}</p>
                    <p class="font-mono text-xs text-gray-500">{{ row.sku }}</p>
                </template>
                <template #quantity="{ row }">
                    <span class="font-medium tabular-nums" :class="row.quantity < 0 ? 'text-red-600' : 'text-green-700'">
                        {{ row.quantity > 0 ? '+' : '' }}{{ qty(row.quantity) }} {{ row.unit }}
                    </span>
                </template>
                <template #unit_cost="{ row }">{{ money(row.unit_cost) }}</template>
                <template #value="{ row }"><span class="tabular-nums">{{ money(row.value) }}</span></template>
            </DataTable>
            <div class="flex justify-end border-t border-gray-100 px-4 py-3 text-sm font-medium">
                Total value impact: <span class="ml-2 tabular-nums" :class="total < 0 ? 'text-red-600' : 'text-green-700'">{{ money(total) }}</span>
            </div>
        </Card>
    </AppLayout>
</template>
