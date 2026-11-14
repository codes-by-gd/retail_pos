<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money } from '@/utils/format';
import { Link } from '@inertiajs/vue3';

defineProps({ sessions: { type: Object, required: true } });

const columns = [
    { key: 'id', label: 'Shift' },
    { key: 'opened_at', label: 'Opened' },
    { key: 'closed_at', label: 'Closed' },
    { key: 'sales_total', label: 'Sales', align: 'right' },
    { key: 'difference', label: 'Cash difference', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Register shifts">
        <PageHeader title="Register shifts" description="Opening float, sales and cash count for every shift at this branch." />
        <Card :padded="false">
            <DataTable :columns="columns" :rows="sessions.data" empty="No shifts yet.">
                <template #id="{ row }">
                    <Link :href="route('register-sessions.show', row.id)" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ row.id }} · {{ row.register }}</Link>
                </template>
                <template #opened_at="{ row }"><span class="text-xs">{{ dateTime(row.opened_at) }}<br /><span class="text-gray-500">{{ row.opened_by }}</span></span></template>
                <template #closed_at="{ row }">
                    <span v-if="row.closed_at" class="text-xs">{{ dateTime(row.closed_at) }}<br /><span class="text-gray-500">{{ row.closed_by }}</span></span>
                    <span v-else class="text-gray-400">—</span>
                </template>
                <template #sales_total="{ row }"><span class="tabular-nums">{{ money(row.sales_total) }}</span></template>
                <template #difference="{ row }">
                    <span v-if="row.difference === null" class="text-gray-400">—</span>
                    <span v-else class="font-medium tabular-nums" :class="row.difference < 0 ? 'text-red-600' : row.difference > 0 ? 'text-amber-700' : 'text-green-700'">
                        {{ row.difference > 0 ? '+' : '' }}{{ money(row.difference) }}
                    </span>
                </template>
                <template #status="{ row }"><Badge :color="row.status === 'open' ? 'green' : 'gray'">{{ row.status === 'open' ? 'Open' : 'Closed' }}</Badge></template>
            </DataTable>
            <Pagination :paginator="sessions" />
        </Card>
    </AppLayout>
</template>
