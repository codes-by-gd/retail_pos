<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money, qty } from '@/utils/format';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    transfer: { type: Object, required: true },
    canReceive: { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
});

const statusBadge = {
    in_transit: { color: 'amber', label: 'In transit' },
    received: { color: 'green', label: 'Received' },
    cancelled: { color: 'gray', label: 'Cancelled' },
};
const busy = ref(false);
const cancelling = ref(false);

const receive = () => router.post(route('stock-transfers.receive', props.transfer.id), {}, { onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
const cancel = () =>
    router.post(route('stock-transfers.cancel', props.transfer.id), {}, { onFinish: () => (cancelling.value = false) });

const columns = [
    { key: 'name', label: 'Item' },
    { key: 'quantity', label: 'Quantity', align: 'right' },
    { key: 'unit_cost', label: 'Unit cost', align: 'right' },
];
</script>

<template>
    <AppLayout :title="transfer.reference_no">
        <PageHeader :title="`Transfer ${transfer.reference_no}`">
            <template #actions>
                <SecondaryButton v-if="canCancel" @click="cancelling = true">Cancel transfer</SecondaryButton>
                <PrimaryButton v-if="canReceive" type="button" :disabled="busy" @click="receive">Receive stock</PrimaryButton>
            </template>
        </PageHeader>

        <p
            v-if="transfer.status === 'in_transit' && !canReceive && !canCancel"
            class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800"
        >
            Switch to <strong>{{ transfer.to }}</strong> to receive this transfer.
        </p>

        <Card class="mb-6">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-500">From</dt><dd class="font-medium">{{ transfer.from }}</dd></div>
                <div><dt class="text-gray-500">To</dt><dd class="font-medium">{{ transfer.to }}</dd></div>
                <div>
                    <dt class="text-gray-500">Status</dt>
                    <dd><Badge :color="statusBadge[transfer.status].color">{{ statusBadge[transfer.status].label }}</Badge></dd>
                </div>
                <div><dt class="text-gray-500">Sent</dt><dd class="font-medium">{{ dateTime(transfer.created_at) }} · {{ transfer.user }}</dd></div>
                <div v-if="transfer.received_at"><dt class="text-gray-500">Received</dt><dd class="font-medium">{{ dateTime(transfer.received_at) }} · {{ transfer.receiver }}</dd></div>
                <div v-if="transfer.note" class="sm:col-span-3"><dt class="text-gray-500">Note</dt><dd>{{ transfer.note }}</dd></div>
            </dl>
        </Card>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="transfer.items">
                <template #name="{ row }">
                    <p class="font-medium text-gray-900">{{ row.name }}</p>
                    <p class="font-mono text-xs text-gray-500">{{ row.sku }}</p>
                </template>
                <template #quantity="{ row }"><span class="tabular-nums">{{ qty(row.quantity) }} {{ row.unit }}</span></template>
                <template #unit_cost="{ row }">{{ money(row.unit_cost) }}</template>
            </DataTable>
        </Card>

        <ConfirmModal
            :show="cancelling"
            title="Cancel transfer?"
            message="All items go back into stock at the sending branch."
            confirm-text="Cancel transfer"
            @confirm="cancel"
            @close="cancelling = false"
        />
    </AppLayout>
</template>
