<script setup>
import Card from '@/Components/Card.vue';
import CatalogTabs from '@/Components/CatalogTabs.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RowActions from '@/Components/RowActions.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useCrudModal } from '@/composables/useCrudModal';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownIcon, ArrowUpIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

defineProps({ attributes: { type: Array, required: true } });

const crud = useCrudModal(() => ({ name: '', sort_order: 0, values: [] }), {
    store: 'attributes.store',
    update: 'attributes.update',
    destroy: 'attributes.destroy',
});
const { form } = crud;

const openEdit = (row) => {
    crud.openEdit(row);
    // deep copy so editing doesn't touch the list
    form.values = row.values.map((v) => ({ ...v }));
};

const newValue = ref('');
const addValues = () => {
    // "S, M, L" adds three values
    newValue.value
        .split(',')
        .map((v) => v.trim())
        .filter((v) => v && !form.values.some((x) => x.value.toLowerCase() === v.toLowerCase()))
        .forEach((value) => form.values.push({ id: null, value, used: 0 }));
    newValue.value = '';
};
const move = (i, step) => {
    const j = i + step;
    if (j < 0 || j >= form.values.length) return;
    const list = [...form.values];
    [list[i], list[j]] = [list[j], list[i]];
    form.values = list;
};
</script>

<template>
    <AppLayout title="Catalog setup">
        <PageHeader title="Catalog setup" description="Masters used when creating products.">
            <template #actions>
                <PrimaryButton type="button" @click="crud.openCreate"><PlusIcon class="h-4 w-4" /> Add attribute</PrimaryButton>
            </template>
        </PageHeader>
        <CatalogTabs />

        <p class="mb-4 text-sm text-gray-500">
            Sizes and colours used to build product variants. The order of values here is the order on screens and labels (S, M, L…).
        </p>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card v-for="a in attributes" :key="a.id" :title="a.name" :description="a.used ? `Used by ${a.used} variants` : 'Not used yet'">
                <template #actions>
                    <RowActions :deletable="!a.used" @edit="openEdit(a)" @delete="crud.deleting.value = a" />
                </template>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="v in a.values"
                        :key="v.id"
                        class="rounded-md border px-2 py-0.5 text-sm"
                        :class="v.used ? 'border-indigo-200 bg-indigo-50 text-indigo-800' : 'border-gray-200 text-gray-700'"
                        :title="v.used ? `${v.used} variants` : ''"
                        >{{ v.value }}</span
                    >
                </div>
            </Card>
        </div>

        <Modal :show="crud.show.value" max-width="lg" @close="crud.show.value = false">
            <form class="space-y-4 p-6" @submit.prevent="crud.submit">
                <h3 class="text-base font-semibold text-gray-900">{{ crud.editing.value ? `Edit ${crud.editing.value.name}` : 'New attribute' }}</h3>
                <div class="grid grid-cols-3 gap-4">
                    <FormField class="col-span-2" label="Name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" placeholder="e.g. Size, Colour, Waist" autofocus />
                    </FormField>
                    <FormField label="Position" :error="form.errors.sort_order" hint="Lower comes first in names">
                        <TextInput v-model="form.sort_order" type="number" min="0" />
                    </FormField>
                </div>

                <FormField label="Values" :error="form.errors.values">
                    <div class="flex gap-2">
                        <TextInput v-model="newValue" placeholder="Type values, e.g. S, M, L" @keydown.enter.prevent="addValues" />
                        <SecondaryButton @click="addValues">Add</SecondaryButton>
                    </div>
                </FormField>
                <ul class="max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200">
                    <li v-for="(v, i) in form.values" :key="v.id ?? `new-${v.value}`" class="flex items-center gap-2 px-3 py-1.5">
                        <TextInput v-model="v.value" class="!py-1" />
                        <span v-if="v.used" class="whitespace-nowrap text-xs text-gray-400">{{ v.used }} used</span>
                        <button type="button" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)">
                            <ArrowUpIcon class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
                            :disabled="i === form.values.length - 1"
                            @click="move(i, 1)"
                        >
                            <ArrowDownIcon class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="p-1 text-gray-400 hover:text-red-600 disabled:opacity-30"
                            :disabled="v.used > 0"
                            :title="v.used ? 'In use — cannot remove' : 'Remove'"
                            @click="form.values.splice(i, 1)"
                        >
                            <XMarkIcon class="h-4 w-4" />
                        </button>
                    </li>
                    <li v-if="!form.values.length" class="px-3 py-4 text-center text-sm text-gray-500">No values yet.</li>
                </ul>
                <InputError v-for="(msg, key) in form.errors" v-show="key.startsWith('values.')" :key="key" :message="msg" />

                <div class="flex justify-end gap-2 pt-2">
                    <SecondaryButton @click="crud.show.value = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="!!crud.deleting.value"
            title="Delete attribute?"
            :message="`“${crud.deleting.value?.name}” and its values will be removed.`"
            @confirm="crud.confirmDestroy"
            @close="crud.deleting.value = null"
        />
    </AppLayout>
</template>
