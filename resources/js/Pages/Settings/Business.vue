<script setup>
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    settings: { type: Object, required: true },
    states: { type: Array, required: true },
    mailConfigured: { type: Boolean, default: false },
    logoUrl: { type: String, default: null },
});

const logoForm = useForm({ logo: null });
const logoInput = ref(null);
const uploadLogo = (e) => {
    logoForm.logo = e.target.files[0] ?? null;
    if (logoForm.logo) logoForm.post(route('settings.business.logo'), { preserveScroll: true, forceFormData: true, onFinish: () => (e.target.value = '') });
};
const removeLogo = () => router.delete(route('settings.business.logo.destroy'), { preserveScroll: true });

const { business, invoice, tax, pos, alerts, loyalty } = props.settings;

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
        print_after_sale: !!pos.print_after_sale,
        accept_card: !!pos.accept_card,
        accept_upi: !!pos.accept_upi,
    },
    loyalty: {
        enabled: !!loyalty?.enabled,
        earn_per: Number(loyalty?.earn_per ?? 100),
        point_value: Number(loyalty?.point_value ?? 1),
        min_redeem: Number(loyalty?.min_redeem ?? 100),
        max_redeem_percent: Number(loyalty?.max_redeem_percent ?? 50),
        expiry_months: Number(loyalty?.expiry_months ?? 12),
    },
    alerts: {
        discount_percent: Number(alerts?.discount_percent ?? 20),
        cash_difference: Number(alerts?.cash_difference ?? 100),
        email_enabled: !!alerts?.email_enabled,
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
            <Card title="Logo" description="Printed on receipts (in black & white on thermal printers), invoices and reports, and shown in the app header. PNG, JPG or WebP up to 1 MB; a wide logo on a white or transparent background works best.">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex h-20 w-48 items-center justify-center rounded-md border border-dashed border-gray-300 bg-white p-2">
                        <img v-if="logoUrl" :src="logoUrl" alt="Shop logo" class="max-h-full max-w-full object-contain" />
                        <span v-else class="text-xs text-gray-400">No logo</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="uploadLogo" />
                        <SecondaryButton type="button" :disabled="logoForm.processing" @click="logoInput.click()">{{ logoUrl ? 'Change logo' : 'Upload logo' }}</SecondaryButton>
                        <SecondaryButton v-if="logoUrl" type="button" @click="removeLogo">Remove</SecondaryButton>
                    </div>
                    <p v-if="logoForm.errors.logo" class="w-full text-sm text-red-600">{{ logoForm.errors.logo }}</p>
                </div>
            </Card>

            <Card title="Business profile" description="Shown on receipts and GST invoices.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FormField
                        label="Invoice prefix (optional)"
                        :error="form.errors['invoice.prefix']"
                        hint="Up to 4 characters. Numbers look like BR1/2627/00001 (GST allows max 16 characters)."
                    >
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

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
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
                        <Toggle v-model="form.pos.print_after_sale" label="Print receipt after each sale" />
                        <Toggle v-model="form.pos.accept_card" label="Accept card payments" />
                        <Toggle v-model="form.pos.accept_upi" label="Accept UPI payments" />
                        <FormField label="Default receipt size" :error="form.errors['pos.receipt_size']">
                            <SelectInput v-model="form.pos.receipt_size" :options="receiptSizes" />
                        </FormField>
                    </div>
                </Card>
            </div>

            <Card title="Loyalty points" description="Named customers earn points on every bill and can pay with them at the POS (not the walk-in customer).">
                <Toggle v-model="form.loyalty.enabled" label="Loyalty points on" />
                <div v-if="form.loyalty.enabled" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label="₹ spent for 1 point" :error="form.errors['loyalty.earn_per']"><TextInput v-model.number="form.loyalty.earn_per" type="number" min="1" /></FormField>
                    <FormField label="1 point is worth (₹)" :error="form.errors['loyalty.point_value']"><TextInput v-model.number="form.loyalty.point_value" type="number" min="0.01" step="0.01" /></FormField>
                    <FormField label="Min. points to use" :error="form.errors['loyalty.min_redeem']"><TextInput v-model.number="form.loyalty.min_redeem" type="number" min="1" /></FormField>
                    <FormField label="Max. % of a bill" :error="form.errors['loyalty.max_redeem_percent']"><TextInput v-model.number="form.loyalty.max_redeem_percent" type="number" min="1" max="100" /></FormField>
                    <FormField label="Expire after (months)" hint="0 = never" :error="form.errors['loyalty.expiry_months']"><TextInput v-model.number="form.loyalty.expiry_months" type="number" min="0" /></FormField>
                </div>
                <p v-if="form.loyalty.enabled" class="mt-3 text-xs text-gray-500">
                    A ₹{{ form.loyalty.earn_per * 10 }} bill earns 10 points, worth ₹{{ (10 * form.loyalty.point_value).toFixed(2) }} — about {{ ((form.loyalty.point_value / form.loyalty.earn_per) * 100).toFixed(1) }}% back.
                </p>
            </Card>

            <Card title="Alerts" description="When managers get a notification. Each person chooses which alerts they want on their Notifications page.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <FormField label="Large discount alert (%)" :error="form.errors['alerts.discount_percent']" hint="Total discount on a bill at or above this. 0 = off.">
                        <TextInput v-model.number="form.alerts.discount_percent" type="number" min="0" max="100" step="0.5" />
                    </FormField>
                    <FormField label="Cash short / excess alert (₹)" :error="form.errors['alerts.cash_difference']" hint="When a register closes with at least this difference.">
                        <TextInput v-model.number="form.alerts.cash_difference" type="number" min="0" step="1" />
                    </FormField>
                    <div>
                        <Toggle v-model="form.alerts.email_enabled" label="Also send alerts by email"
                            :description="mailConfigured ? 'To users who switch email on for an alert.' : 'Email is not set up on the server (MAIL_* in .env) — alerts stay in the app.'" />
                    </div>
                </div>
            </Card>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">Save settings</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
