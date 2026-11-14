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

defineProps({ brands: { type: Array, required: true } });

const crud = useCrudModal(() => ({ name: '', is_active: true }), {
    store: 'brands.store',
    update: 'brands.update',
    destroy: 'brands.destroy',
});
const { form } = crud;

const columns = [
    { key: 'name', label: 'Brand' },
    { key: 'products_count', label: 'Products', align: 'right' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Catalog setup">
        <PageHeader title="Catalog setup" description="Masters used when creating products.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add brand</PrimaryButton>
            </template>
        </PageHeader>
        <CatalogTabs />

        <Card :padded="false">
            <DataTable :columns="columns" :rows="brands" empty="No brands yet.">
                <template #name="{ row }"><span class="font-medium text-gray-900">{{ row.name }}</span></template>
                <template #is_active="{ row }">
                    <Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
                </template>
                <template #actions="{ row }">
                    <RowActions @edit="crud.openEdit(row)" @delete="crud.deleting.value = row" />
                </template>
            </DataTable>
        </Card>

        <Modal :show="crud.show.value" max-width="md" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="crud.submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit brand' : 'New brand' }}</h3>
                <FormField label="Name" :error="form.errors.name" required>
                    <TextInput v-model="form.name" autofocus />
                </FormField>
                <Toggle v-model="form.is_active" label="Active" description="Inactive brands are hidden when creating products." />
                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!crud.deleting.value"
            title="Delete brand?"
            :message="`“${crud.deleting.value?.name}” will be removed.`"
            @confirm="crud.confirmDestroy"
            @close="crud.deleting.value = null"
        />
    </AppLayout>
</template>
