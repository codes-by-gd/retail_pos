<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RowActions from '@/Components/RowActions.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { useCrudModal } from '@/composables/useCrudModal';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ groups: { type: Array, required: true } });

const crud = useCrudModal(() => ({ name: '', price_type: 'retail', discount_percent: 0 }), {
    store: 'customer-groups.store',
    update: 'customer-groups.update',
    destroy: 'customer-groups.destroy',
});
const { form } = crud;

const columns = [
    { key: 'name', label: 'Group' },
    { key: 'price_type', label: 'Price used at billing' },
    { key: 'discount_percent', label: 'Member discount', align: 'right' },
    { key: 'contacts_count', label: 'Customers', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Customer groups">
        <PageHeader title="Customer groups" description="Group customers for wholesale pricing or member discounts (e.g. Gold Members 5% off).">
            <template #actions>
                <ButtonLink :href="route('customers.index')" variant="secondary">Back to customers</ButtonLink>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add group</PrimaryButton>
            </template>
        </PageHeader>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="groups" empty="No groups yet.">
                <template #name="{ row }"><span class="font-medium text-gray-900">{{ row.name }}</span></template>
                <template #price_type="{ row }">
                    <Badge :color="row.price_type === 'wholesale' ? 'amber' : 'gray'">{{ row.price_type === 'wholesale' ? 'Wholesale price' : 'Retail price' }}</Badge>
                </template>
                <template #discount_percent="{ row }">{{ Number(row.discount_percent) ? `${Number(row.discount_percent)}%` : '—' }}</template>
                <template #actions="{ row }">
                    <RowActions :deletable="!row.contacts_count" @edit="crud.openEdit(row)" @delete="crud.deleting.value = row" />
                </template>
            </DataTable>
        </Card>

        <Modal :show="crud.show.value" max-width="md" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="crud.submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit group' : 'New group' }}</h3>
                <FormField label="Name" :error="form.errors.name" required>
                    <TextInput v-model="form.name" autofocus />
                </FormField>
                <FormField label="Price at billing" :error="form.errors.price_type" hint="Wholesale uses each item's wholesale price when set.">
                    <SelectInput v-model="form.price_type" :options="[{ value: 'retail', label: 'Retail (selling price)' }, { value: 'wholesale', label: 'Wholesale price' }]" />
                </FormField>
                <FormField label="Member discount %" :error="form.errors.discount_percent" hint="Applied automatically to the bill.">
                    <TextInput v-model="form.discount_percent" type="number" min="0" max="100" step="0.01" />
                </FormField>
                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!crud.deleting.value"
            title="Delete group?"
            :message="`“${crud.deleting.value?.name}” will be removed.`"
            @confirm="crud.confirmDestroy"
            @close="crud.deleting.value = null"
        />
    </AppLayout>
</template>
