<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
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
import { ArrowLeftIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';

defineProps({ categories: { type: Array, required: true } });

const crud = useCrudModal(() => ({ name: '', is_active: true }), {
    store: 'expense-categories.store', update: 'expense-categories.update', destroy: 'expense-categories.destroy',
});
const { form } = crud;
const columns = [
    { key: 'name', label: 'Category' },
    { key: 'expenses_count', label: 'Expenses', align: 'right' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Expense categories">
        <div class="mb-4"><Link :href="route('expenses.index')" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800"><ArrowLeftIcon class="h-4 w-4" /> Expenses</Link></div>
        <PageHeader title="Expense categories" description="Group running costs for the expenses and profit & loss reports.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add category</PrimaryButton>
            </template>
        </PageHeader>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="categories" empty="No categories yet.">
                <template #name="{ row }"><span class="font-medium text-gray-900">{{ row.name }}</span></template>
                <template #is_active="{ row }"><Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge></template>
                <template #actions="{ row }"><RowActions :deletable="!row.expenses_count" @edit="crud.openEdit(row)" @delete="crud.deleting.value = row" /></template>
            </DataTable>
        </Card>

        <Modal :show="crud.show.value" max-width="md" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="crud.submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit category' : 'New category' }}</h3>
                <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" autofocus /></FormField>
                <Toggle v-model="form.is_active" label="Active" description="Inactive categories can't be chosen for new expenses." />
                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :show="!!crud.deleting.value" title="Delete category?" :message="`“${crud.deleting.value?.name}” will be removed.`"
            @confirm="crud.confirmDestroy" @close="crud.deleting.value = null" />
    </AppLayout>
</template>
