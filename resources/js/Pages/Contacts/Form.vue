<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    kind: { type: String, required: true },
    contact: { type: Object, default: null },
    states: { type: Array, required: true },
    groups: { type: Array, default: () => [] },
    defaultStateCode: { type: String, default: null },
    canBoth: { type: Boolean, default: false },
});

const editing = computed(() => !!props.contact);
const walkIn = computed(() => !!props.contact?.is_walk_in);
const plural = computed(() => `${props.kind}s`);

const opening = props.contact?.opening_balance ?? 0;
const form = useForm({
    type: props.contact?.type ?? props.kind,
    name: props.contact?.name ?? '',
    company_name: props.contact?.company_name ?? '',
    phone: props.contact?.phone ?? '',
    alt_phone: props.contact?.alt_phone ?? '',
    email: props.contact?.email ?? '',
    gstin: props.contact?.gstin ?? '',
    // Only new contacts start with the branch state; never invent a state for an existing contact.
    state_code: props.contact ? props.contact.state_code : props.defaultStateCode,
    address: props.contact?.address ?? '',
    city: props.contact?.city ?? '',
    pincode: props.contact?.pincode ?? '',
    customer_group_id: props.contact?.customer_group_id ?? null,
    credit_limit: props.contact?.credit_limit ?? 0,
    payment_terms_days: props.contact?.payment_terms_days ?? '',
    date_of_birth: props.contact?.date_of_birth ?? '',
    anniversary: props.contact?.anniversary ?? '',
    notes: props.contact?.notes ?? '',
    is_active: props.contact?.is_active ?? true,
    opening_balance: Math.abs(opening) || '',
});
// They owe us (Dr) / we owe them (Cr)
const openingSide = ref(opening < 0 || (!editing.value && props.kind === 'supplier') ? 'payable' : 'receivable');

const isCustomer = computed(() => ['customer', 'both'].includes(form.type));
const isSupplier = computed(() => ['supplier', 'both'].includes(form.type));

// A GSTIN's first two digits are the state code.
watch(
    () => form.gstin,
    (g) => {
        const code = (g ?? '').trim().slice(0, 2);
        if (/^\d{2}$/.test(code) && props.states.some((s) => s.value === code)) form.state_code = code;
    },
);

const typeOptions = computed(() => [
    { value: 'customer', label: 'Customer' },
    { value: 'supplier', label: 'Supplier' },
    ...(props.canBoth ? [{ value: 'both', label: 'Both (buys from and sells to us)' }] : []),
]);

const submit = () => {
    form.transform((d) => ({
        ...d,
        opening_balance: d.opening_balance === '' ? 0 : (openingSide.value === 'payable' ? -1 : 1) * Number(d.opening_balance),
    }));
    editing.value ? form.put(route(`${plural.value}.update`, props.contact.id)) : form.post(route(`${plural.value}.store`));
};
useUnsavedGuard(() => form.isDirty && !form.processing && !form.wasSuccessful);
</script>

<template>
    <AppLayout :title="editing ? `Edit ${contact.name}` : `New ${kind}`">
        <PageHeader :title="editing ? `Edit ${contact.name}` : `New ${kind}`" :description="editing ? contact.code : ''" />

        <form class="max-w-4xl space-y-6" @submit.prevent="submit">
            <Card title="Contact">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" autofocus />
                    </FormField>
                    <FormField v-if="!walkIn" label="Type" :error="form.errors.type" required>
                        <SelectInput v-model="form.type" :options="typeOptions" />
                    </FormField>
                    <FormField
                        label="Mobile number"
                        :error="form.errors.phone"
                        :required="form.type === 'customer' && !walkIn"
                        hint="Used to find the customer at the counter. +91 / 0 prefixes are removed."
                    >
                        <TextInput v-model="form.phone" type="tel" inputmode="tel" />
                    </FormField>
                    <FormField label="Alternate phone" :error="form.errors.alt_phone">
                        <TextInput v-model="form.alt_phone" type="tel" />
                    </FormField>
                    <FormField label="Email" :error="form.errors.email">
                        <TextInput v-model="form.email" type="email" />
                    </FormField>
                    <FormField label="Company / firm name" :error="form.errors.company_name">
                        <TextInput v-model="form.company_name" />
                    </FormField>
                </div>
            </Card>

            <Card v-if="!walkIn" title="Address & GST" description="The state decides CGST + SGST (same state) or IGST (other state) on bills.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="GSTIN" :error="form.errors.gstin" hint="For business customers & suppliers. Fills the state.">
                        <TextInput v-model="form.gstin" maxlength="15" class="uppercase" />
                    </FormField>
                    <FormField label="State" :error="form.errors.state_code">
                        <SelectInput v-model="form.state_code" :options="states" placeholder="— Select —" />
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
                </div>
            </Card>

            <Card v-if="isCustomer && !walkIn" title="Customer details">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Customer group" :error="form.errors.customer_group_id" hint="Decides retail/wholesale price and member discount.">
                        <SelectInput v-model="form.customer_group_id" :options="groups" placeholder="— None —" />
                    </FormField>
                    <FormField label="Credit limit (₹)" :error="form.errors.credit_limit" hint="0 = no credit (udhaar) sales.">
                        <TextInput v-model="form.credit_limit" type="number" min="0" step="0.01" />
                    </FormField>
                    <FormField label="Date of birth" :error="form.errors.date_of_birth" hint="For birthday offers.">
                        <TextInput v-model="form.date_of_birth" type="date" />
                    </FormField>
                    <FormField label="Anniversary" :error="form.errors.anniversary">
                        <TextInput v-model="form.anniversary" type="date" />
                    </FormField>
                </div>
            </Card>

            <Card v-if="isSupplier" title="Supplier details">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Payment terms (days)" :error="form.errors.payment_terms_days" hint="e.g. 30 = pay within 30 days of the bill.">
                        <TextInput v-model="form.payment_terms_days" type="number" min="0" max="365" />
                    </FormField>
                </div>
            </Card>

            <Card v-if="!walkIn" title="Opening balance & notes">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Opening balance (₹)" :error="form.errors.opening_balance" hint="Amount outstanding before using this system.">
                        <div class="flex gap-2">
                            <TextInput v-model="form.opening_balance" type="number" min="0" step="0.01" />
                            <select
                                v-model="openingSide"
                                class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="receivable">They owe us (Dr)</option>
                                <option value="payable">We owe them (Cr)</option>
                            </select>
                        </div>
                    </FormField>
                    <div class="flex items-end pb-2">
                        <Toggle v-model="form.is_active" label="Active" description="Inactive contacts are hidden from searches." />
                    </div>
                    <FormField class="sm:col-span-2" label="Notes" :error="form.errors.notes">
                        <TextareaInput v-model="form.notes" rows="2" :placeholder="isCustomer ? 'e.g. Prefers slim fit, size 40' : 'e.g. Delivers on Mondays'" />
                    </FormField>
                </div>
            </Card>

            <div class="flex justify-end gap-3">
                <Link
                    :href="editing ? route(`${plural}.show`, contact.id) : route(`${plural}.index`)"
                    class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100"
                    >Cancel</Link
                >
                <PrimaryButton :disabled="form.processing">{{ editing ? 'Save changes' : `Create ${kind}` }}</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
