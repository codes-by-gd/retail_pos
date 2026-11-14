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
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { useCrudModal } from '@/composables/useCrudModal';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { computed } from 'vue';

const props = defineProps({
    categories: { type: Array, required: true },
    parentOptions: { type: Array, required: true },
});

const crud = useCrudModal(() => ({ name: '', code: '', parent_id: null, is_active: true }), {
    store: 'categories.store',
    update: 'categories.update',
    destroy: 'categories.destroy',
});
const { form } = crud;

// Show the tree path as the name, sorted like the option list.
const order = computed(() => Object.fromEntries(props.parentOptions.map((o, i) => [o.value, { i, label: o.label }])));
const rows = computed(() =>
    [...props.categories]
        .map((c) => ({ ...c, path: order.value[c.id]?.label ?? c.name, depth: (order.value[c.id]?.label ?? '').split(' › ').length - 1 }))
        .sort((a, b) => (order.value[a.id]?.i ?? 1e9) - (order.value[b.id]?.i ?? 1e9)),
);
// A category can't be its own parent.
const parentChoices = computed(() => props.parentOptions.filter((o) => o.value !== crud.editing.value?.id));

const columns = [
    { key: 'name', label: 'Category' },
    { key: 'code', label: 'Code' },
    { key: 'products_count', label: 'Products', align: 'right' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Catalog setup">
        <PageHeader title="Catalog setup" description="Masters used when creating products.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add category</PrimaryButton>
            </template>
        </PageHeader>
        <CatalogTabs />

        <Card :padded="false">
            <DataTable :columns="columns" :rows="rows" empty="No categories yet.">
                <template #name="{ row }">
                    <span :style="{ paddingLeft: `${row.depth * 1.25}rem` }" class="font-medium text-gray-900">
                        <span v-if="row.depth" class="text-gray-300">└ </span>{{ row.name }}
                    </span>
                    <span v-if="row.children_count" class="ml-2 text-xs text-gray-400">{{ row.children_count }} sub</span>
                </template>
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
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? 'Edit category' : 'New category' }}</h3>
                <FormField label="Name" :error="form.errors.name" required>
                    <TextInput v-model="form.name" autofocus />
                </FormField>
                <FormField label="Parent category" :error="form.errors.parent_id" hint="Leave empty for a top-level category.">
                    <SelectInput v-model="form.parent_id" :options="parentChoices" placeholder="— None —" />
                </FormField>
                <FormField label="Code" :error="form.errors.code">
                    <TextInput v-model="form.code" maxlength="20" />
                </FormField>
                <Toggle v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!crud.deleting.value"
            title="Delete category?"
            :message="`“${crud.deleting.value?.name}” will be removed.`"
            @confirm="crud.confirmDestroy"
            @close="crud.deleting.value = null"
        />
    </AppLayout>
</template>
