<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    branches: { type: Array, required: true },
    states: { type: Array, required: true },
    defaultStateCode: { type: String, default: null },
});

const columns = [
    { key: 'name', label: 'Branch' },
    { key: 'code', label: 'Code' },
    { key: 'state', label: 'State' },
    { key: 'gstin', label: 'GSTIN' },
    { key: 'users_count', label: 'Users', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

const blank = () => ({
    name: '',
    code: '',
    gstin: '',
    state_code: props.defaultStateCode,
    address: '',
    city: '',
    pincode: '',
    phone: '',
    email: '',
    is_active: true,
});

const form = useForm(blank());
const editing = ref(null);
const showForm = ref(false);
const deleting = ref(null);

const openCreate = () => {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (branch) => {
    editing.value = branch;
    form.defaults(Object.fromEntries(Object.keys(blank()).map((k) => [k, branch[k] ?? ''])));
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editing.value
        ? form.put(route('settings.branches.update', editing.value.id), options)
        : form.post(route('settings.branches.store'), options);
};

const destroy = () => {
    router.delete(route('settings.branches.destroy', deleting.value.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};
</script>

<template>
    <AppLayout title="Branches">
        <PageHeader title="Branches" description="Store locations. Stock, cash registers and invoice numbers are kept per branch.">
            <template #actions>
                <PrimaryButton type="button" @click="openCreate"><PlusIcon class="h-4 w-4" /> Add branch</PrimaryButton>
            </template>
        </PageHeader>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="branches">
                <template #name="{ row }">
                    <div class="font-medium text-gray-900">{{ row.name }}</div>
                    <div class="text-xs text-gray-500">{{ row.city }}</div>
                </template>
                <template #code="{ row }"><span class="font-mono text-xs">{{ row.code }}</span></template>
                <template #state="{ row }">{{ row.state_name }}</template>
                <template #gstin="{ row }"><span class="font-mono text-xs">{{ row.gstin || 'Business GSTIN' }}</span></template>
                <template #status="{ row }">
                    <Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
                </template>
                <template #actions="{ row }">
                    <div class="flex justify-end gap-1">
                        <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-indigo-600" title="Edit" @click="openEdit(row)">
                            <PencilSquareIcon class="h-4 w-4" />
                        </button>
                        <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-600" title="Delete" @click="deleting = row">
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </div>
                </template>
            </DataTable>
        </Card>

        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <form class="p-6" @submit.prevent="submit">
                <h3 class="mb-4 text-base font-semibold text-gray-900">{{ editing ? 'Edit branch' : 'New branch' }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Branch name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" autofocus />
                    </FormField>
                    <FormField label="Code" :error="form.errors.code" hint="Short code used in invoice numbers, e.g. BR1" required>
                        <TextInput v-model="form.code" maxlength="10" class="uppercase" />
                    </FormField>
                    <FormField label="State" :error="form.errors.state_code" required>
                        <SelectInput v-model="form.state_code" :options="states" />
                    </FormField>
                    <FormField label="GSTIN" :error="form.errors.gstin" hint="Leave empty to use the business GSTIN.">
                        <TextInput v-model="form.gstin" maxlength="15" class="uppercase" />
                    </FormField>
                    <FormField class="sm:col-span-2" label="Address" :error="form.errors.address">
                        <TextInput v-model="form.address" />
                    </FormField>
                    <FormField label="City" :error="form.errors.city">
                        <TextInput v-model="form.city" />
                    </FormField>
                    <FormField label="PIN code" :error="form.errors.pincode">
                        <TextInput v-model="form.pincode" maxlength="6" inputmode="numeric" />
                    </FormField>
                    <FormField label="Phone" :error="form.errors.phone">
                        <TextInput v-model="form.phone" type="tel" />
                    </FormField>
                    <FormField label="Email" :error="form.errors.email">
                        <TextInput v-model="form.email" type="email" />
                    </FormField>
                    <div class="sm:col-span-2">
                        <Toggle v-model="form.is_active" label="Active" description="Inactive branches are hidden from the branch switcher." />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ editing ? 'Save changes' : 'Create branch' }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!deleting"
            title="Delete branch?"
            :message="`“${deleting?.name}” will be removed. This cannot be undone.`"
            @confirm="destroy"
            @close="deleting = null"
        />
    </AppLayout>
</template>
