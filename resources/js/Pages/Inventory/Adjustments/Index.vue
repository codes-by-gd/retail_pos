<script setup>
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';

defineProps({ adjustments: { type: Object, required: true } });

const columns = [
    { key: 'reference_no', label: 'Reference' },
    { key: 'date', label: 'Date' },
    { key: 'reason', label: 'Reason' },
    { key: 'items_count', label: 'Items', align: 'right' },
    { key: 'user', label: 'By' },
];
</script>

<template>
    <AppLayout title="Stock adjustments">
        <PageHeader title="Stock adjustments" description="Corrections for damage, expiry, loss and stock counts at this branch.">
            <template #actions>
                <ButtonLink :href="route('stock-adjustments.create')"><PlusIcon class="h-4 w-4" /> New adjustment</ButtonLink>
            </template>
        </PageHeader>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="adjustments.data" empty="No adjustments yet.">
                <template #reference_no="{ row }">
                    <Link :href="route('stock-adjustments.show', row.id)" class="font-mono text-xs font-medium text-indigo-600 hover:text-indigo-800">
                        {{ row.reference_no }}
                    </Link>
                </template>
                <template #date="{ row }">{{ date(row.date) }}</template>
                <template #reason="{ row }">
                    {{ row.reason }}
                    <p v-if="row.note" class="truncate text-xs text-gray-500">{{ row.note }}</p>
                </template>
            </DataTable>
            <Pagination :paginator="adjustments" />
        </Card>
    </AppLayout>
</template>
