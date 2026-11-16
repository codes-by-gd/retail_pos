<script setup>
import DateRangeFilter from '@/Components/DateRangeFilter.vue';
import { useListFilters } from '@/composables/useListFilters';
import BarList from '@/Components/BarList.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/utils/format';
import { LockClosedIcon, PaperClipIcon, PencilSquareIcon, PlusIcon, TagIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    expenses: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    categories: { type: Array, required: true },
    methods: { type: Array, required: true },
    registers: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const filters = useListFilters('expenses.index', {
    from: props.filters.from, to: props.filters.to, search: props.filters.search ?? '',
    category: props.filters.category ? Number(props.filters.category) : null, method: props.filters.method ?? null,
});

const methodLabel = Object.fromEntries(props.methods.map((m) => [m.value, m.label]));
const activeCategories = computed(() => props.categories.filter((c) => c.active || c.value === form.expense_category_id));

// ---- add / edit ----
const blank = () => ({
    expense_category_id: null, date: props.today, amount: '', gst_amount: '', method: 'cash', reference: '', paid_to: '', note: '',
    register_session_id: props.registers[0]?.value ?? null, attachment: null, remove_attachment: false,
});
const form = useForm(blank());
const editing = ref(null);
const show = ref(false);
const deleting = ref(null);
const fileInput = ref(null);

const openCreate = () => { editing.value = null; form.defaults(blank()); form.reset(); form.clearErrors(); show.value = true; };
const openEdit = (row) => {
    editing.value = row;
    form.defaults({ ...blank(), ...Object.fromEntries(Object.keys(blank()).filter((k) => k in row).map((k) => [k, row[k] ?? ''])),
        register_session_id: row.register_session_id, attachment: null, remove_attachment: false });
    form.reset(); form.clearErrors(); show.value = true;
};
const submit = () => {
    const options = { preserveScroll: true, forceFormData: true, onSuccess: () => (show.value = false) };
    form.transform((d) => ({ ...d, register_session_id: d.method === 'cash' ? d.register_session_id : null, ...(editing.value ? { _method: 'put' } : {}) }));
    editing.value ? form.post(route('expenses.update', editing.value.id), options) : form.post(route('expenses.store'), options);
};
onMounted(() => new URLSearchParams(window.location.search).has('add') && openCreate());
const destroy = () => router.delete(route('expenses.destroy', deleting.value.id), { preserveScroll: true, onFinish: () => (deleting.value = null) });

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'category', label: 'Category' },
    { key: 'paid_to', label: 'Paid to / note' },
    { key: 'method', label: 'Mode' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Expenses">
        <PageHeader title="Expenses" :description="`${summary.count} entries · ${money(summary.total)} for the selected period (this branch)`">
            <template #actions>
                <Link :href="route('expense-categories.index')"><SecondaryButton type="button"><TagIcon class="h-4 w-4" /> Categories</SecondaryButton></Link>
                <PrimaryButton type="button" @click="openCreate"><PlusIcon class="h-4 w-4" /> Add expense</PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <Card :padded="false" class="xl:col-span-2">
                <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-5">
                    <SearchInput v-model="filters.search" class="lg:col-span-1" placeholder="Ref., payee, note…" />
                    <DateRangeFilter v-model:from="filters.from" v-model:to="filters.to" :allow-any="false" />
                    <SelectInput v-model="filters.category" :options="categories" placeholder="All categories" />
                    <SelectInput v-model="filters.method" :options="methods" placeholder="Any mode" />
                </div>
                <DataTable :columns="columns" :rows="expenses.data" empty="No expenses in this period.">
                    <template #date="{ row }">
                        <span class="whitespace-nowrap">{{ date(row.date) }}</span>
                        <p class="whitespace-nowrap font-mono text-xs text-gray-500">{{ row.reference_no }}</p>
                    </template>
                    <template #category="{ row }">{{ row.category }}</template>
                    <template #paid_to="{ row }">
                        <span class="text-gray-900">{{ row.paid_to || '—' }}</span>
                        <p v-if="row.note" class="max-w-xs truncate text-xs text-gray-500" :title="row.note">{{ row.note }}</p>
                    </template>
                    <template #method="{ row }">
                        {{ methodLabel[row.method] }}
                        <p v-if="row.register" class="text-xs text-gray-500">from {{ row.register }}</p>
                        <p v-else-if="row.reference" class="text-xs text-gray-500">{{ row.reference }}</p>
                    </template>
                    <template #amount="{ row }">
                        <span class="font-medium tabular-nums">{{ money(row.amount) }}</span>
                        <p v-if="row.gst_amount" class="text-xs text-gray-500">GST {{ money(row.gst_amount) }}</p>
                    </template>
                    <template #actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <a v-if="row.has_attachment" :href="route('expenses.attachment', row.id)" target="_blank" class="rounded-sm p-1.5 text-gray-400 hover:bg-gray-100 hover:text-indigo-600" title="Bill photo"><PaperClipIcon class="h-4 w-4" /></a>
                            <span v-if="row.locked" class="p-1.5 text-gray-300" :title="row.locked"><LockClosedIcon class="h-4 w-4" /></span>
                            <template v-else>
                                <button class="rounded-sm p-1.5 text-gray-400 hover:bg-gray-100 hover:text-indigo-600" title="Edit" @click="openEdit(row)"><PencilSquareIcon class="h-4 w-4" /></button>
                                <button class="rounded-sm p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-600" title="Delete" @click="deleting = row"><TrashIcon class="h-4 w-4" /></button>
                            </template>
                        </div>
                    </template>
                </DataTable>
                <Pagination :paginator="expenses" />
            </Card>

            <Card title="By category" :description="`${date(filters.from)} – ${date(filters.to)}`">
                <BarList :items="summary.byCategory.map((c) => ({ label: c.name, value: c.total, hint: `· ${c.count}` }))" empty="No expenses yet." />
            </Card>
        </div>

        <Modal :show="show" max-width="2xl" @close="show = false">
            <form class="p-6" @submit.prevent="submit">
                <h3 class="mb-4 text-base font-semibold text-gray-900">{{ editing ? `Edit ${editing.reference_no}` : 'New expense' }}</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Category" :error="form.errors.expense_category_id" required>
                        <SelectInput v-model="form.expense_category_id" :options="activeCategories" placeholder="Choose…" />
                    </FormField>
                    <FormField label="Date" :error="form.errors.date" required><TextInput v-model="form.date" type="date" :max="today" /></FormField>
                    <FormField label="Amount paid (₹)" :error="form.errors.amount" required><TextInput v-model="form.amount" type="number" min="0" step="0.01" autofocus /></FormField>
                    <FormField label="GST included (₹)" :error="form.errors.gst_amount" hint="If the bill shows GST — kept out of profit."><TextInput v-model="form.gst_amount" type="number" min="0" step="0.01" /></FormField>
                    <FormField label="Paid to" :error="form.errors.paid_to"><TextInput v-model="form.paid_to" placeholder="Landlord, MGVCL, courier…" /></FormField>
                    <FormField label="Mode" :error="form.errors.method" required><SelectInput v-model="form.method" :options="methods" /></FormField>
                    <FormField v-if="form.method === 'cash'" label="Cash from drawer" hint="Counts in that shift's cash; leave empty if paid from the safe.">
                        <SelectInput v-model="form.register_session_id" :options="registers" placeholder="Not from a register" />
                    </FormField>
                    <FormField v-else label="Reference" :error="form.errors.reference"><TextInput v-model="form.reference" placeholder="UTR / cheque / bill no." /></FormField>
                    <FormField label="Bill photo" :error="form.errors.attachment" hint="JPG, PNG or PDF, up to 4 MB.">
                        <input ref="fileInput" type="file" accept="image/*,application/pdf" class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm"
                            @change="form.attachment = $event.target.files[0] ?? null" />
                        <label v-if="editing?.has_attachment && !form.attachment" class="mt-1 flex items-center gap-2 text-xs text-gray-600">
                            <input v-model="form.remove_attachment" type="checkbox" class="rounded-sm border-gray-300 text-indigo-600" /> Remove the current photo
                        </label>
                    </FormField>
                    <FormField class="sm:col-span-2" label="Note" :error="form.errors.note"><TextareaInput v-model="form.note" rows="2" /></FormField>
                </div>
                <InputError :message="form.errors.register_session_id" class="mt-2" />
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="show = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ editing ? 'Save changes' : 'Save expense' }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :show="!!deleting" title="Delete expense?" :message="`${deleting?.reference_no} (${money(deleting?.amount)}) will be removed.`"
            @confirm="destroy" @close="deleting = null" />
    </AppLayout>
</template>
