<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/utils/format';
import { ArrowDownLeftIcon, ArrowUpRightIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    transfers: { type: Object, required: true },
    filters: { type: Object, required: true },
    pendingIncoming: { type: Number, default: 0 },
});

const statusBadge = {
    in_transit: { color: 'amber', label: 'In transit' },
    received: { color: 'green', label: 'Received' },
    cancelled: { color: 'gray', label: 'Cancelled' },
};
const tabs = [
    { value: null, label: 'All' },
    { value: 'incoming', label: 'Incoming' },
    { value: 'outgoing', label: 'Outgoing' },
];
const setDirection = (direction) => router.get(route('stock-transfers.index'), direction ? { direction } : {}, { preserveState: true });

const columns = [
    { key: 'reference_no', label: 'Reference' },
    { key: 'route', label: 'From → To' },
    { key: 'items_count', label: 'Items', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Sent' },
];
</script>

<template>
    <AppLayout title="Stock transfers">
        <PageHeader title="Stock transfers" description="Move stock between branches. Stock leaves when sent and arrives when the other branch receives it.">
            <template #actions>
                <ButtonLink :href="route('stock-transfers.create')"><PlusIcon class="h-4 w-4" /> New transfer</ButtonLink>
            </template>
        </PageHeader>

        <div
            v-if="pendingIncoming"
            class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            {{ pendingIncoming }} incoming transfer{{ pendingIncoming > 1 ? 's are' : ' is' }} waiting to be received at this branch.
        </div>

        <Card :padded="false">
            <div class="flex gap-1 border-b border-gray-100 px-4 pt-3">
                <button
                    v-for="t in tabs"
                    :key="t.label"
                    class="-mb-px border-b-2 px-3 pb-2 text-sm font-medium"
                    :class="(filters.direction ?? null) === t.value ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    @click="setDirection(t.value)"
                >
                    {{ t.label }}
                </button>
            </div>
            <DataTable :columns="columns" :rows="transfers.data" empty="No transfers.">
                <template #reference_no="{ row }">
                    <Link :href="route('stock-transfers.show', row.id)" class="font-mono text-xs font-medium text-indigo-600 hover:text-indigo-800">
                        {{ row.reference_no }}
                    </Link>
                </template>
                <template #route="{ row }">
                    <span class="flex items-center gap-1.5">
                        <ArrowDownLeftIcon v-if="row.incoming" class="h-4 w-4 text-green-600" title="Incoming" />
                        <ArrowUpRightIcon v-else class="h-4 w-4 text-gray-400" title="Outgoing" />
                        {{ row.from }} → {{ row.to }}
                    </span>
                </template>
                <template #status="{ row }">
                    <Badge :color="statusBadge[row.status].color">{{ statusBadge[row.status].label }}</Badge>
                </template>
                <template #created_at="{ row }">
                    <span class="text-xs text-gray-600">{{ dateTime(row.created_at) }} · {{ row.user }}</span>
                </template>
            </DataTable>
            <Pagination :paginator="transfers" />
        </Card>
    </AppLayout>
</template>
