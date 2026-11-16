<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import Card from '@/Components/Card.vue';
import ContactPicker from '@/Components/ContactPicker.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import VariantPicker from '@/Components/VariantPicker.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { calculate } from '@/Pos/gst';
import { money, qty } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    today: { type: String, required: true },
    prefill: { type: Object, default: null },
    branchState: { type: String, default: null },
    roundOff: { type: Boolean, default: true },
    registers: { type: Array, default: () => [] },
    paymentMethods: { type: Array, required: true },
});

const { can } = usePermissions();
const supplier = ref(props.prefill?.supplier ?? null);
const lines = ref(
    (props.prefill?.items ?? []).map((i) => ({ ...i, discount_type: 'percent', discount_value: 0 })),
);
const form = useForm({
    purchase_order_id: props.prefill?.purchase_order_id ?? null,
    supplier_invoice_no: '',
    supplier_invoice_date: props.today,
    received_date: props.today,
    other_charges: 0,
    notes: '',
    update_cost_price: true,
    payment: { amount: '', method: 'bank', reference: '', register_session_id: props.registers[0]?.value ?? null },
});

const add = (item) => {
    const existing = lines.value.find((l) => l.id === item.id && !l.purchase_order_item_id);
    if (existing) return existing.quantity++;
    lines.value.push({ ...item, quantity: 1, unit_cost: item.purchase_price, discount_type: 'percent', discount_value: 0, purchase_order_item_id: null });
};

const interState = computed(() => !!(supplier.value?.state_code && props.branchState && supplier.value.state_code !== props.branchState));
const preview = computed(() =>
    calculate(
        lines.value.map((l, i) => ({
            key: i, unit_price: l.unit_cost || 0, quantity: l.quantity || 0, price_includes_tax: false, rate: l.gst_rate, cess_rate: l.gst_cess ?? 0,
            threshold: l.gst_threshold, rate_above: l.gst_rate_above,
            discount_type: Number(l.discount_value) > 0 ? l.discount_type : null, discount_value: l.discount_value || 0,
        })),
        null,
        interState.value,
        false,
    ),
);
const beforeRound = computed(() => preview.value.totals.grand_total + Number(form.other_charges || 0));
const grand = computed(() => (props.roundOff ? Math.round(beforeRound.value) : Math.round(beforeRound.value * 100) / 100));
const landed = (i) => {
    const l = lines.value[i];
    const taxable = preview.value.lines[i]?.taxable_value ?? 0;
    const share = preview.value.totals.taxable_total ? (Number(form.other_charges || 0) * taxable) / preview.value.totals.taxable_total : 0;
    return Number(l.quantity) ? (taxable + share) / Number(l.quantity) : 0;
};

const submit = () => {
    form.transform((d) => ({
        ...d,
        supplier_id: supplier.value?.id ?? null,
        items: lines.value.map((l) => ({
            variant_id: l.id, purchase_order_item_id: l.purchase_order_item_id ?? null, quantity: l.quantity, unit_cost: l.unit_cost,
            discount_type: l.discount_type, discount_value: l.discount_value || 0,
        })),
        payment: Number(d.payment.amount) > 0 ? { ...d.payment, register_session_id: d.payment.method === 'cash' ? d.payment.register_session_id : null } : null,
    }));
    form.post(route('purchases.store'));
};
useUnsavedGuard(() => (form.isDirty || lines.value.length > (props.prefill?.items?.length ?? 0)) && !form.processing && !form.wasSuccessful);
</script>

<template>
    <AppLayout title="Receive goods">
        <PageHeader title="Receive goods" :description="prefill ? `Against purchase order ${prefill.reference_no}` : 'Enter the supplier\'s bill as it arrives with the goods.'" />

        <form class="space-y-6" @submit.prevent="submit">
            <Card title="Supplier bill">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <FormField class="md:col-span-2" label="Supplier" :error="form.errors.supplier_id || form.errors.purchase_order_id" required>
                        <ContactPicker v-model="supplier" kind="supplier" :disabled="!!prefill" />
                    </FormField>
                    <FormField label="Supplier bill no." :error="form.errors.supplier_invoice_no"><TextInput v-model="form.supplier_invoice_no" /></FormField>
                    <FormField label="Bill date" :error="form.errors.supplier_invoice_date"><TextInput v-model="form.supplier_invoice_date" type="date" :max="today" /></FormField>
                    <FormField label="Received on" :error="form.errors.received_date" required><TextInput v-model="form.received_date" type="date" :max="today" /></FormField>
                </div>
                <p v-if="interState" class="mt-3 text-xs text-indigo-700">Supplier is in another state — IGST.</p>
            </Card>

            <Card title="Items received" description="Rates excluding GST, as on the supplier's bill.">
                <VariantPicker placeholder="Search item, article no. or scan a tag…" @select="add" />
                <InputError :message="form.errors.items" class="mt-2" />
                <div v-if="lines.length" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase text-gray-500">
                            <tr><th class="py-2">Item</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">Rate</th><th class="px-2 py-2">Discount</th>
                                <th class="px-2 py-2 text-right">GST</th><th class="px-2 py-2 text-right">Amount</th><th class="px-2 py-2 text-right">Landed / pc</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in lines" :key="`${l.id}-${l.purchase_order_item_id ?? 'x'}`" class="border-t border-gray-100 align-top">
                                <td class="py-2 pr-2">
                                    <p class="font-medium text-gray-900">{{ l.product_name }}</p>
                                    <p class="text-xs text-gray-500">{{ l.variant_name }} · {{ l.sku }} · stock {{ qty(l.stock) }}<span v-if="l.purchase_order_item_id"> · on PO</span></p>
                                </td>
                                <td class="px-2 py-2"><TextInput v-model.number="l.quantity" type="number" min="0" :step="l.allow_decimal ? '0.001' : '1'" class="!w-20" />
                                    <InputError :message="form.errors[`items.${i}.quantity`]" /></td>
                                <td class="px-2 py-2"><TextInput v-model.number="l.unit_cost" type="number" min="0" step="0.01" class="!w-28" /></td>
                                <td class="px-2 py-2"><div class="flex gap-1">
                                    <select v-model="l.discount_type" class="rounded-md border-gray-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="percent">%</option><option value="amount">₹</option></select>
                                    <TextInput v-model.number="l.discount_value" type="number" min="0" step="0.01" class="!w-20" /></div></td>
                                <td class="px-2 py-2 text-right text-xs text-gray-500">{{ preview.lines[i]?.gst_rate }}%</td>
                                <td class="px-2 py-2 text-right font-medium tabular-nums">{{ money(preview.lines[i]?.line_total ?? 0) }}</td>
                                <td class="px-2 py-2 text-right text-xs tabular-nums text-gray-500">{{ money(landed(i)) }}</td>
                                <td class="py-2 pl-2"><button type="button" class="p-1.5 text-gray-400 hover:text-red-600" @click="lines.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <label class="mt-4 flex items-center gap-2 text-sm text-gray-700">
                    <input v-model="form.update_cost_price" type="checkbox" class="rounded-sm border-gray-300 text-indigo-600" />
                    Update each item's purchase price to this bill's rate
                </label>
            </Card>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Payment now" description="Leave empty to pay later from the supplier's page.">
                    <div v-if="can('payments.manage')" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Amount paid (₹)" :error="form.errors['payment.amount']"><TextInput v-model="form.payment.amount" type="number" min="0" step="0.01" /></FormField>
                        <FormField label="Mode"><SelectInput v-model="form.payment.method" :options="paymentMethods" /></FormField>
                        <FormField label="Reference"><TextInput v-model="form.payment.reference" placeholder="UTR / cheque no." /></FormField>
                        <FormField v-if="form.payment.method === 'cash'" label="Cash from drawer">
                            <SelectInput v-model="form.payment.register_session_id" :options="registers" placeholder="Not from a register" />
                        </FormField>
                    </div>
                    <p v-else class="text-sm text-gray-500">You can record the bill; a manager records payments.</p>
                    <FormField class="mt-4" label="Notes"><TextareaInput v-model="form.notes" rows="2" /></FormField>
                </Card>
                <Card title="Bill total">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Items</dt><dd class="tabular-nums">{{ money(preview.totals.subtotal) }}</dd></div>
                        <div v-if="preview.totals.line_discount_total" class="flex justify-between text-green-700"><dt>Discount</dt><dd class="tabular-nums">−{{ money(preview.totals.line_discount_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>Taxable</dt><dd class="tabular-nums">{{ money(preview.totals.taxable_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>{{ interState ? 'IGST' : 'CGST + SGST' }}</dt><dd class="tabular-nums">{{ money(preview.totals.tax_total) }}</dd></div>
                        <div class="flex items-center justify-between"><dt>Freight / other charges</dt>
                            <dd><TextInput v-model.number="form.other_charges" type="number" min="0" step="0.01" class="!w-28 !py-1 text-right" /></dd></div>
                        <div v-if="grand !== beforeRound" class="flex justify-between text-gray-500"><dt>Round off</dt><dd class="tabular-nums">{{ money(grand - beforeRound) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-200 pt-1 text-lg font-semibold"><dt>Bill total</dt><dd class="tabular-nums">{{ money(grand) }}</dd></div>
                        <div v-if="Number(form.payment.amount) > 0" class="flex justify-between text-amber-700"><dt>Balance to pay later</dt><dd class="tabular-nums">{{ money(Math.max(0, grand - Number(form.payment.amount))) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500">Stock comes in at the landed cost (rate after discount + share of freight, excluding GST, which you claim as input credit).</p>
                </Card>
            </div>

            <div class="flex justify-end gap-3">
                <Link :href="route('purchases.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing || !lines.length || !supplier">Save & add to stock</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
