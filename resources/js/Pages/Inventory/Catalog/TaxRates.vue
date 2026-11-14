<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import CatalogTabs from '@/Components/CatalogTabs.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RowActions from '@/Components/RowActions.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { useCrudModal } from '@/composables/useCrudModal';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { ref, watch } from 'vue';

defineProps({ taxRates: { type: Array, required: true } });

const crud = useCrudModal(() => ({ name: '', rate: '', cess_rate: 0, threshold_amount: '', rate_above: '', is_active: true }), {
    store: 'tax-rates.store',
    update: 'tax-rates.update',
    destroy: 'tax-rates.destroy',
});
const { form } = crud;

const half = (r) => Number(r) / 2;
const priceBased = ref(false);
watch(
    () => crud.show.value,
    (open) => open && (priceBased.value = form.threshold_amount !== '' && form.threshold_amount !== null),
);
const submit = () => {
    if (!priceBased.value) {
        form.threshold_amount = '';
        form.rate_above = '';
    }
    crud.submit();
};
const columns = [
    { key: 'name', label: 'Name' },
    { key: 'rate', label: 'GST' },
    { key: 'cess_rate', label: 'Cess', align: 'right' },
    { key: 'products_count', label: 'Products', align: 'right' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Catalog setup">
        <PageHeader title="Catalog setup" description="Masters used when creating products.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add GST rate</PrimaryButton>
            </template>
        </PageHeader>
        <CatalogTabs />

        <Card :padded="false">
            <DataTable :columns="columns" :rows="taxRates">
                <template #name="{ row }"><span class="font-medium text-gray-900">{{ row.name }}</span></template>
                <template #rate="{ row }">
                    <template v-if="row.threshold_amount !== null">
                        <span class="font-medium">{{ Number(row.rate) }}%</span> up to {{ money(row.threshold_amount) }} per piece,
                        <span class="font-medium">{{ Number(row.rate_above) }}%</span> above
                    </template>
                    <span v-else class="font-medium">{{ Number(row.rate) }}%</span>
                    <p class="text-xs text-gray-500">Within state: CGST {{ half(row.rate) }}% + SGST {{ half(row.rate) }}% · other states: IGST</p>
                </template>
                <template #cess_rate="{ row }">{{ Number(row.cess_rate) ? `${Number(row.cess_rate)}%` : '—' }}</template>
                <template #is_active="{ row }">
                    <Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
                </template>
                <template #actions="{ row }">
                    <RowActions :deletable="!row.products_count" @edit="crud.openEdit(row)" @delete="crud.deleting.value = row" />
                </template>
            </DataTable>
        </Card>

        <Modal :show="crud.show.value" max-width="md" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit GST rate' : 'New GST rate' }}</h3>
                <p v-if="crud.editing.value?.products_count" class="rounded-md bg-amber-50 p-3 text-xs text-amber-800">
                    Used by {{ crud.editing.value.products_count }} products — the percentage can't be changed, only the name and status.
                </p>
                <FormField label="Name" :error="form.errors.name" required>
                    <TextInput v-model="form.name" placeholder="GST 18%" autofocus />
                </FormField>
                <div class="grid grid-cols-2 gap-4">
                    <FormField :label="priceBased ? 'GST % up to the limit' : 'GST %'" :error="form.errors.rate" required>
                        <TextInput v-model="form.rate" type="number" step="0.01" min="0" max="100" />
                    </FormField>
                    <FormField label="Cess %" :error="form.errors.cess_rate">
                        <TextInput v-model="form.cess_rate" type="number" step="0.01" min="0" max="100" />
                    </FormField>
                </div>
                <Toggle
                    v-model="priceBased"
                    label="Rate depends on selling price"
                    description="For garments: a higher rate applies when one piece sells above a limit."
                />
                <div v-if="priceBased" class="grid grid-cols-2 gap-4">
                    <FormField label="Price limit per piece (₹)" :error="form.errors.threshold_amount" required hint="Taxable value, excl. GST">
                        <TextInput v-model="form.threshold_amount" type="number" step="0.01" min="0" />
                    </FormField>
                    <FormField label="GST % above the limit" :error="form.errors.rate_above" required>
                        <TextInput v-model="form.rate_above" type="number" step="0.01" min="0" max="100" />
                    </FormField>
                </div>
                <Toggle v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!crud.deleting.value"
            title="Delete GST rate?"
            :message="`“${crud.deleting.value?.name}” will be removed.`"
            @confirm="crud.confirmDestroy"
            @close="crud.deleting.value = null"
        />
    </AppLayout>
</template>
