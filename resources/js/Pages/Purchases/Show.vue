<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaymentFormModal from '@/Components/PaymentFormModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { printUrl } from '@/Pos/helpers';
import { date, money, qty } from '@/utils/format';
import { ArrowUturnLeftIcon, BanknotesIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    purchase: { type: Object, required: true },
    registers: { type: Array, default: () => [] },
    paymentMethods: { type: Array, required: true },
});
const { can } = usePermissions();
const paying = ref(false);
const returning = ref(false);
const returnForm = useForm({ items: [], reason: '' });

const openReturn = () => {
    returnForm.reset();
    returnForm.clearErrors();
    returnForm.items = props.purchase.items.map((i) => ({ purchase_item_id: i.id, quantity: 0 }));
    returning.value = true;
};
const submitReturn = () =>
    returnForm.transform((d) => ({ ...d, items: d.items.filter((i) => Number(i.quantity) > 0) })).post(route('purchases.returns.store', props.purchase.id), {
        preserveScroll: true,
        onSuccess: () => (returning.value = false),
    });

// Tags for the delivery: one copy per piece received.
const labelsUrl = computed(() => {
    const p = new URLSearchParams();
    props.purchase.items.forEach((i) => {
        p.append('variants[]', i.variant_id);
        p.append(`copies[${i.variant_id}]`, Math.round(i.quantity));
    });
    return `${route('labels.index')}?${p.toString()}`;
});
const statusColor = { paid: 'green', partial: 'amber', due: 'red' };
</script>

<template>
    <AppLayout :title="purchase.reference_no">
        <PageHeader :title="`Purchase ${purchase.reference_no}`" :description="`${purchase.supplier.name} · received ${date(purchase.received_date)} by ${purchase.user}`">
            <template #actions>
                <ButtonLink v-if="can('labels.print')" :href="labelsUrl" variant="secondary"><PrinterIcon class="h-4 w-4" /> Print tags</ButtonLink>
                <SecondaryButton v-if="can('purchases.manage') && purchase.items.some((i) => i.returned < i.quantity)" @click="openReturn">
                    <ArrowUturnLeftIcon class="h-4 w-4" /> Return goods
                </SecondaryButton>
                <PrimaryButton v-if="can('payments.manage') && purchase.due_amount > 0" type="button" @click="paying = true"><BanknotesIcon class="h-4 w-4" /> Pay supplier</PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Items" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-500">
                            <tr><th class="px-4 py-2 text-left">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-right">Taxable</th>
                                <th class="px-4 py-2 text-right">GST</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2 text-right">Landed / pc</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="i in purchase.items" :key="i.id">
                                <td class="px-4 py-2">{{ i.name }}<span v-if="i.variant" class="text-gray-500"> · {{ i.variant }}</span><p class="text-xs text-gray-500">{{ i.sku }}</p>
                                    <p v-if="i.returned" class="text-xs text-amber-700">{{ qty(i.returned) }} returned</p></td>
                                <td class="px-4 py-2 text-right">{{ qty(i.quantity) }} {{ i.unit }}</td>
                                <td class="px-4 py-2 text-right">{{ money(i.unit_cost) }}<p v-if="i.discount" class="text-xs text-green-700">−{{ money(i.discount) }}</p></td>
                                <td class="px-4 py-2 text-right">{{ money(i.taxable) }}</td>
                                <td class="px-4 py-2 text-right">{{ money(i.tax) }}<p class="text-xs text-gray-500">{{ i.gst_rate }}%</p></td>
                                <td class="px-4 py-2 text-right font-medium">{{ money(i.total) }}</td>
                                <td class="px-4 py-2 text-right text-gray-500">{{ money(i.landed) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
            <div class="space-y-6">
                <Card title="Bill">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Supplier</dt><dd><Link :href="route('suppliers.show', purchase.supplier.id)" class="text-indigo-600 hover:underline">{{ purchase.supplier.name }}</Link></dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Bill no.</dt><dd class="font-mono">{{ purchase.supplier_invoice_no ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Bill date</dt><dd>{{ purchase.supplier_invoice_date ? date(purchase.supplier_invoice_date) : '—' }}</dd></div>
                        <div v-if="purchase.order" class="flex justify-between"><dt class="text-gray-500">Order</dt><dd><Link :href="route('purchase-orders.show', purchase.order.id)" class="font-mono text-indigo-600 hover:underline">{{ purchase.order.reference_no }}</Link></dd></div>
                        <div class="flex justify-between border-t border-gray-100 pt-2"><dt>Taxable</dt><dd>{{ money(purchase.taxable_total) }}</dd></div>
                        <div class="flex justify-between"><dt>{{ purchase.is_inter_state ? 'IGST' : 'CGST + SGST' }}</dt><dd>{{ money(purchase.cgst_total + purchase.sgst_total + purchase.igst_total) }}</dd></div>
                        <div v-if="purchase.other_charges" class="flex justify-between"><dt>Freight / other</dt><dd>{{ money(purchase.other_charges) }}</dd></div>
                        <div v-if="purchase.round_off" class="flex justify-between text-gray-500"><dt>Round off</dt><dd>{{ money(purchase.round_off) }}</dd></div>
                        <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd>{{ money(purchase.grand_total) }}</dd></div>
                        <div v-for="p in purchase.payments" :key="p.reference_no" class="flex justify-between text-green-700">
                            <dt>Paid · <button type="button" class="font-mono underline" @click="printUrl(p.print_url)">{{ p.reference_no }}</button> ({{ p.method }})</dt><dd>{{ money(p.amount) }}</dd>
                        </div>
                        <div v-for="r in purchase.returns" :key="r.id" class="flex justify-between text-gray-600">
                            <dt>Returned · <button type="button" class="font-mono underline" @click="printUrl(r.print_url)">{{ r.reference_no }}</button></dt><dd>−{{ money(r.total) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-gray-100 pt-2 font-semibold" :class="purchase.due_amount > 0 ? 'text-red-700' : 'text-green-700'">
                            <dt>Still to pay</dt><dd>{{ money(purchase.due_amount) }}</dd>
                        </div>
                    </dl>
                    <div class="mt-3"><Badge :color="statusColor[purchase.payment_status]">{{ { paid: 'Paid', partial: 'Part paid', due: 'Unpaid' }[purchase.payment_status] }}</Badge></div>
                </Card>
            </div>
        </div>

        <PaymentFormModal
            :show="paying"
            :contact="{ id: purchase.supplier.id, name: purchase.supplier.name }"
            direction="out"
            :suggested="purchase.due_amount"
            :methods="paymentMethods"
            :registers="registers"
            @close="paying = false"
        />

        <Modal :show="returning" max-width="xl" @close="returning = false">
            <form class="space-y-4 p-6" @submit.prevent="submitReturn">
                <h3 class="text-base font-semibold text-gray-900">Return goods to {{ purchase.supplier.name }}</h3>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500"><tr><th class="py-1">Item</th><th class="py-1 text-right">Can return</th><th class="py-1 text-right">Return</th></tr></thead>
                    <tbody>
                        <tr v-for="(i, n) in purchase.items" :key="i.id" class="border-t border-gray-100">
                            <td class="py-2">{{ i.name }}<span v-if="i.variant" class="text-gray-500"> · {{ i.variant }}</span></td>
                            <td class="py-2 text-right text-gray-500">{{ qty(i.quantity - i.returned) }}</td>
                            <td class="py-2 text-right">
                                <TextInput v-if="returnForm.items[n]" v-model.number="returnForm.items[n].quantity" type="number" min="0" :max="i.quantity - i.returned" class="!w-20 text-right" />
                                <InputError :message="returnForm.errors[`items.${n}.quantity`]" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <InputError :message="returnForm.errors.items" />
                <FormField label="Reason"><TextInput v-model="returnForm.reason" placeholder="e.g. Stitching defect, wrong colour" /></FormField>
                <p class="text-xs text-gray-500">Creates a debit note, removes the stock and reduces what you owe this supplier.</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton @click="returning = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="returnForm.processing">Create debit note</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
