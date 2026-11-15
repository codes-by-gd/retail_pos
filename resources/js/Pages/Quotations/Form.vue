<script setup>
import Card from '@/Components/Card.vue';
import ContactPicker from '@/Components/ContactPicker.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import VariantPicker from '@/Components/VariantPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { calculate } from '@/Pos/gst';
import { money, qty } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    quotation: { type: Object, default: null },
    today: { type: String, required: true },
    defaultValidity: { type: String, required: true },
});

const page = usePage();
const customer = ref(props.quotation?.customer ?? null);
const lines = ref(props.quotation?.items ?? []);
const form = useForm({
    customer_name: props.quotation?.customer_name ?? '',
    customer_phone: props.quotation?.customer_phone ?? '',
    date: props.quotation?.date ?? props.today,
    valid_until: props.quotation?.valid_until ?? props.defaultValidity,
    bill_discount_type: props.quotation?.bill_discount_type ?? 'percent',
    bill_discount_value: props.quotation?.bill_discount_value ?? 0,
    notes: props.quotation?.notes ?? '',
});

const wholesale = computed(() => customer.value?.price_type === 'wholesale');
const priceOf = (l) => (wholesale.value && l.wholesale_price !== null ? l.wholesale_price : l.selling_price);
const branchState = computed(() => page.props.branch?.current?.state_code);
const interState = computed(() => !!(customer.value?.state_code && customer.value.state_code !== branchState.value));

const add = (item) => {
    const existing = lines.value.find((l) => l.id === item.id);
    if (existing) return existing.quantity++;
    lines.value.push({ ...item, quantity: 1, discount_type: 'percent', discount_value: 0 });
};

const preview = computed(() =>
    calculate(
        lines.value.map((l) => ({
            key: l.id, unit_price: priceOf(l), quantity: l.quantity || 0, price_includes_tax: l.price_includes_tax, rate: l.gst_rate,
            cess_rate: l.gst_cess ?? 0, threshold: l.gst_threshold, rate_above: l.gst_rate_above,
            discount_type: Number(l.discount_value) > 0 ? l.discount_type : null, discount_value: l.discount_value || 0,
        })),
        Number(form.bill_discount_value) > 0 ? { type: form.bill_discount_type, value: form.bill_discount_value } : null,
        interState.value,
    ),
);
const lineCalc = (i) => preview.value.lines[i];

const submit = () => {
    form.transform((d) => ({
        ...d,
        customer_id: customer.value?.id ?? null,
        items: lines.value.map((l) => ({ variant_id: l.id, quantity: l.quantity, discount_type: l.discount_type, discount_value: l.discount_value || 0 })),
    }));
    props.quotation ? form.put(route('quotations.update', props.quotation.id)) : form.post(route('quotations.store'));
};
</script>

<template>
    <AppLayout :title="quotation ? `Edit ${quotation.reference_no}` : 'New quotation'">
        <PageHeader :title="quotation ? `Edit quotation ${quotation.reference_no}` : 'New quotation'" />

        <form class="space-y-6" @submit.prevent="submit">
            <Card title="Customer">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <FormField class="md:col-span-2" label="Customer" :error="form.errors.customer_id" hint="Pick a saved customer, or type a name below.">
                        <ContactPicker v-model="customer" kind="customer" />
                    </FormField>
                    <template v-if="!customer">
                        <FormField label="Name" :error="form.errors.customer_name" required><TextInput v-model="form.customer_name" /></FormField>
                        <FormField label="Phone" :error="form.errors.customer_phone"><TextInput v-model="form.customer_phone" type="tel" /></FormField>
                    </template>
                    <FormField label="Date" :error="form.errors.date" required><TextInput v-model="form.date" type="date" /></FormField>
                    <FormField label="Valid until" :error="form.errors.valid_until"><TextInput v-model="form.valid_until" type="date" /></FormField>
                </div>
                <p v-if="wholesale" class="mt-3 text-xs text-amber-700">Wholesale customer — wholesale prices are used.</p>
            </Card>

            <Card title="Items">
                <VariantPicker @select="add" />
                <InputError :message="form.errors.items" class="mt-2" />
                <div v-if="lines.length" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase text-gray-500">
                            <tr><th class="py-2">Item</th><th class="px-2 py-2 text-right">Price</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">Discount</th>
                                <th class="px-2 py-2 text-right">GST</th><th class="px-2 py-2 text-right">Amount</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in lines" :key="l.id" class="border-t border-gray-100 align-top">
                                <td class="py-2 pr-2"><p class="font-medium text-gray-900">{{ l.product_name }}</p><p class="text-xs text-gray-500">{{ l.variant_name }} · {{ l.sku }} · stock {{ qty(l.stock) }}</p></td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ money(priceOf(l)) }}</td>
                                <td class="px-2 py-2"><TextInput v-model.number="l.quantity" type="number" min="0" :step="l.allow_decimal ? '0.001' : '1'" class="!w-20" /></td>
                                <td class="px-2 py-2">
                                    <div class="flex gap-1">
                                        <select v-model="l.discount_type" class="rounded-md border-gray-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="percent">%</option><option value="amount">₹</option></select>
                                        <TextInput v-model.number="l.discount_value" type="number" min="0" step="0.01" class="!w-24" />
                                    </div>
                                    <InputError :message="form.errors[`items.${i}.discount_value`]" />
                                </td>
                                <td class="px-2 py-2 text-right text-xs text-gray-500">{{ lineCalc(i)?.gst_rate }}%</td>
                                <td class="px-2 py-2 text-right font-medium tabular-nums">{{ money(lineCalc(i)?.line_total ?? 0) }}</td>
                                <td class="py-2 pl-2"><button type="button" class="p-1.5 text-gray-400 hover:text-red-600" @click="lines.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Notes">
                    <TextareaInput v-model="form.notes" rows="4" placeholder="Delivery date, alteration terms, advance required…" />
                </Card>
                <Card title="Totals">
                    <div class="mb-3 flex items-center gap-2 text-sm">
                        <span class="text-gray-600">Bill discount</span>
                        <select v-model="form.bill_discount_type" class="rounded-md border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="percent">%</option><option value="amount">₹</option></select>
                        <TextInput v-model.number="form.bill_discount_value" type="number" min="0" step="0.01" class="!w-24 !py-1" />
                    </div>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ money(preview.totals.subtotal) }}</dd></div>
                        <div class="flex justify-between text-green-700"><dt>Discounts</dt><dd class="tabular-nums">−{{ money(preview.totals.line_discount_total + preview.totals.bill_discount_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>Taxable</dt><dd class="tabular-nums">{{ money(preview.totals.taxable_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>{{ interState ? 'IGST' : 'CGST + SGST' }}</dt><dd class="tabular-nums">{{ money(preview.totals.tax_total) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-200 pt-1 text-lg font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ money(preview.totals.grand_total) }}</dd></div>
                    </dl>
                </Card>
            </div>

            <div class="flex justify-end gap-3">
                <Link :href="quotation ? route('quotations.show', quotation.id) : route('quotations.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing || !lines.length">{{ quotation ? 'Save changes' : 'Save quotation' }}</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
