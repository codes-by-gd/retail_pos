<script setup>
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
    states: { type: Array, required: true },
});

const { business, invoice, tax, pos } = props.settings;

const form = useForm({
    business: {
        name: business.name ?? '',
        legal_name: business.legal_name ?? '',
        gstin: business.gstin ?? '',
        state_code: business.state_code,
        address: business.address ?? '',
        city: business.city ?? '',
        pincode: business.pincode ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        financial_year_start: business.financial_year_start,
    },
    invoice: { prefix: invoice.prefix ?? '', footer: invoice.footer ?? '', terms: invoice.terms ?? '' },
    tax: { prices_include_tax: !!tax.prices_include_tax, round_off: !!tax.round_off },
    pos: {
        allow_negative_stock: !!pos.allow_negative_stock,
        beep_on_scan: !!pos.beep_on_scan,
        receipt_size: pos.receipt_size,
    },
});

const receiptSizes = [
    { value: '58mm', label: '58 mm thermal' },
    { value: '80mm', label: '80 mm thermal' },
    { value: 'a4', label: 'A4 invoice' },
];

const submit = () => form.put(route('settings.business.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout title="Settings">
        <PageHeader title="Business settings" description="Details printed on invoices and defaults used across the system." />

        <form class="space-y-6" @submit.prevent="submit">
            <Card title="Business profile" description="Shown on receipts and GST invoices.">
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="Business / store name" :error="form.errors['business.name']" required>
                        <TextInput v-model="form.business.name" />
                    </FormField>
                    <FormField label="Legal name (as per GST)" :error="form.errors['business.legal_name']">
                        <TextInput v-model="form.business.legal_name" />
                    </FormField>
                    <FormField label="GSTIN" :error="form.errors['business.gstin']" hint="15 characters, e.g. 24AABCR1234A1Z8">
                        <TextInput v-model="form.business.gstin" maxlength="15" class="uppercase" />
                    </FormField>
                    <FormField label="State" :error="form.errors['business.state_code']" required hint="Decides CGST+SGST vs IGST.">
                        <SelectInput v-model="form.business.state_code" :options="states" />
                    </FormField>
                    <FormField class="md:col-span-2" label="Address" :error="form.errors['business.address']">
                        <TextInput v-model="form.business.address" />
                    </FormField>
                    <FormField label="City" :error="form.errors['business.city']">
                        <TextInput v-model="form.business.city" />
                    </FormField>
                    <FormField label="PIN code" :error="form.errors['business.pincode']">
                        <TextInput v-model="form.business.pincode" maxlength="6" inputmode="numeric" />
                    </FormField>
                    <FormField label="Phone" :error="form.errors['business.phone']">
                        <TextInput v-model="form.business.phone" type="tel" />
                    </FormField>
                    <FormField label="Email" :error="form.errors['business.email']">
                        <TextInput v-model="form.business.email" type="email" />
                    </FormField>
                    <FormField
                        label="Financial year starts (MM-DD)"
                        :error="form.errors['business.financial_year_start']"
                        hint="Indian FY: 04-01. Invoice numbers restart each financial year."
                        required
                    >
                        <TextInput v-model="form.business.financial_year_start" maxlength="5" />
                    </FormField>
                </div>
            </Card>

            <Card title="Invoice" description="Numbering and text printed on bills.">
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="Invoice prefix" :error="form.errors['invoice.prefix']" hint="Keep it short: GST invoice numbers are limited to 16 characters." required>
                        <TextInput v-model="form.invoice.prefix" maxlength="10" />
                    </FormField>
                    <FormField label="Receipt footer" :error="form.errors['invoice.footer']">
                        <TextInput v-model="form.invoice.footer" />
                    </FormField>
                    <FormField class="md:col-span-2" label="Terms & conditions" :error="form.errors['invoice.terms']">
                        <TextareaInput v-model="form.invoice.terms" />
                    </FormField>
                </div>
            </Card>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card title="Tax">
                    <div class="space-y-4">
                        <Toggle
                            v-model="form.tax.prices_include_tax"
                            label="Selling prices include GST"
                            description="Default for new products (MRP-style pricing)."
                        />
                        <Toggle v-model="form.tax.round_off" label="Round off bill total" description="Round to the nearest rupee." />
                    </div>
                </Card>

                <Card title="POS">
                    <div class="space-y-4">
                        <Toggle
                            v-model="form.pos.allow_negative_stock"
                            label="Allow selling out-of-stock items"
                            description="Stock can go below zero."
                        />
                        <Toggle v-model="form.pos.beep_on_scan" label="Beep on barcode scan" />
                        <FormField label="Default receipt size" :error="form.errors['pos.receipt_size']">
                            <SelectInput v-model="form.pos.receipt_size" :options="receiptSizes" />
                        </FormField>
                    </div>
                </Card>
            </div>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">Save settings</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
