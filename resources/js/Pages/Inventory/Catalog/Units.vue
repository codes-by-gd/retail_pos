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
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ units: { type: Array, required: true } });

const crud = useCrudModal(() => ({ name: '', short_name: '', allow_decimal: false }), {
    store: 'units.store',
    update: 'units.update',
    destroy: 'units.destroy',
});
const { form } = crud;

const columns = [
    { key: 'name', label: 'Unit' },
    { key: 'short_name', label: 'Short name' },
    { key: 'allow_decimal', label: 'Quantity' },
    { key: 'products_count', label: 'Products', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Catalog setup">
        <PageHeader title="Catalog setup" description="Masters used when creating products.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add unit</PrimaryButton>
            </template>
        </PageHeader>
        <CatalogTabs />

        <Card :padded="false">
            <DataTable :columns="columns" :rows="units">
                <template #name="{ row }"><span class="font-medium text-gray-900">{{ row.name }}</span></template>
                <template #short_name="{ row }"><span class="font-mono text-xs">{{ row.short_name }}</span></template>
                <template #allow_decimal="{ row }">
                    <Badge :color="row.allow_decimal ? 'indigo' : 'gray'">{{ row.allow_decimal ? 'Decimal (loose / weighed)' : 'Whole numbers' }}</Badge>
                </template>
                <template #actions="{ row }">
                    <RowActions :deletable="!row.products_count" @edit="crud.openEdit(row)" @delete="crud.deleting.value = row" />
                </template>
            </DataTable>
        </Card>

        <Modal :show="crud.show.value" max-width="md" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="crud.submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit unit' : 'New unit' }}</h3>
                <FormField label="Name" :error="form.errors.name" required>
                    <TextInput v-model="form.name" placeholder="Kilogram" autofocus />
                </FormField>
                <FormField label="Short name" :error="form.errors.short_name" required hint="Printed on bills, e.g. kg">
                    <TextInput v-model="form.short_name" maxlength="10" />
                </FormField>
                <Toggle v-model="form.allow_decimal" label="Allow decimal quantities" description="For items sold by weight or length (2.5 kg)." />
                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!crud.deleting.value"
            title="Delete unit?"
            :message="`“${crud.deleting.value?.name}” will be removed.`"
            @confirm="crud.confirmDestroy"
            @close="crud.deleting.value = null"
        />
    </AppLayout>
</template>
