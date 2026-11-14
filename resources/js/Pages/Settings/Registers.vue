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
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { useCrudModal } from '@/composables/useCrudModal';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ registers: { type: Array, required: true }, branches: { type: Array, required: true } });

const crud = useCrudModal(() => ({ branch_id: props.branches[0]?.value ?? null, name: '', is_active: true }), {
    store: 'settings.registers.store',
    update: 'settings.registers.update',
    destroy: 'settings.registers.destroy',
});
const { form } = crud;
const columns = [
    { key: 'name', label: 'Register' },
    { key: 'branch', label: 'Branch' },
    { key: 'sessions_count', label: 'Shifts', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Registers">
        <PageHeader title="Cash registers" description="Billing counters. Each counter has its own cash drawer and shifts.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add register</PrimaryButton>
            </template>
        </PageHeader>
        <Card :padded="false">
            <DataTable :columns="columns" :rows="registers">
                <template #name="{ row }"><span class="font-medium text-gray-900">{{ row.name }}</span></template>
                <template #status="{ row }">
                    <Badge v-if="row.is_open" color="green">Shift open</Badge>
                    <Badge v-else :color="row.is_active ? 'gray' : 'red'">{{ row.is_active ? 'Closed' : 'Inactive' }}</Badge>
                </template>
                <template #actions="{ row }"><RowActions :deletable="!row.sessions_count" @edit="crud.openEdit(row)" @delete="crud.deleting.value = row" /></template>
            </DataTable>
        </Card>

        <Modal :show="crud.show.value" max-width="md" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="crud.submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit register' : 'New register' }}</h3>
                <FormField label="Branch" :error="form.errors.branch_id" required><SelectInput v-model="form.branch_id" :options="branches" /></FormField>
                <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" placeholder="e.g. Counter 2" autofocus /></FormField>
                <Toggle v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>
        <ConfirmModal :show="!!crud.deleting.value" title="Delete register?" :message="`“${crud.deleting.value?.name}” will be removed.`" @confirm="crud.confirmDestroy" @close="crud.deleting.value = null" />
    </AppLayout>
</template>
