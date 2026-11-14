<script setup>
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { dateTime, money, qty } from '@/utils/format';
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';
import { apiError, printUrl } from './helpers';

const props = defineProps({ show: { type: Boolean, default: false }, config: { type: Object, required: true } });
const emit = defineEmits(['close', 'exchange', 'done']);

const invoiceNo = ref('');
const sale = ref(null);
const lines = ref([]);
const refundMethod = ref('exchange');
const reason = ref('');
const error = ref('');
const busy = ref(false);
const result = ref(null);
const invoiceInput = ref(null);

const reasons = ['Size does not fit', 'Colour / style change', 'Defect / damaged', 'Customer changed mind'];

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        invoiceNo.value = '';
        sale.value = null;
        result.value = null;
        error.value = '';
        reason.value = '';
        refundMethod.value = 'exchange';
        await nextTick();
        invoiceInput.value?.focus();
    },
);

const lookup = async () => {
    error.value = '';
    try {
        const { data } = await axios.get(route('pos.returns.lookup'), { params: { invoice_no: invoiceNo.value } });
        sale.value = data.sale;
        lines.value = data.sale.items.map((i) => ({ ...i, return_qty: 0, restock: true }));
    } catch (e) {
        error.value = apiError(e);
    }
};

const total = computed(() => lines.value.reduce((s, l) => s + Number(l.return_qty || 0) * l.unit_amount, 0));
const methods = computed(() => [
    { value: 'exchange', label: 'Exchange — use against a new bill now' },
    { value: 'cash', label: 'Cash refund' },
    ...(props.config.accept_upi ? [{ value: 'upi', label: 'Refund by UPI' }] : []),
    ...(props.config.accept_card ? [{ value: 'card', label: 'Refund to card' }] : []),
    ...(sale.value && !sale.value.walk_in ? [{ value: 'account', label: 'Store credit on customer account' }] : []),
]);

const submit = async () => {
    error.value = '';
    busy.value = true;
    try {
        const { data } = await axios.post(route('pos.returns.store'), {
            sale_id: sale.value.id,
            items: lines.value.filter((l) => Number(l.return_qty) > 0).map((l) => ({ sale_item_id: l.id, quantity: l.return_qty, restock: l.restock })),
            refund_method: refundMethod.value,
            reason: reason.value || null,
        });
        result.value = data.return;
        emit('done');
        if (data.return.refund_method === 'exchange') emit('exchange', data.return);
    } catch (e) {
        error.value = apiError(e);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6">
            <h3 class="mb-4 text-base font-semibold text-gray-900">Return / exchange</h3>

            <!-- done -->
            <div v-if="result" class="space-y-4 text-center">
                <p class="text-sm text-gray-500">Credit note</p>
                <p class="font-mono text-lg font-semibold">{{ result.credit_note_no }}</p>
                <p class="text-3xl font-bold text-gray-900">{{ money(result.total) }}</p>
                <p v-if="result.refund_method === 'exchange'" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
                    Added to the bill as exchange credit. Scan the new size / colour and press Pay.
                </p>
                <p v-else-if="result.refund_method === 'cash'" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">Give {{ money(result.total) }} cash back to the customer.</p>
                <div class="flex justify-center gap-2">
                    <SecondaryButton @click="printUrl(result.print_url)">Print credit note</SecondaryButton>
                    <PrimaryButton type="button" @click="emit('close')">Done</PrimaryButton>
                </div>
            </div>

            <template v-else>
                <form class="flex gap-2" @submit.prevent="lookup">
                    <input
                        ref="invoiceInput"
                        v-model="invoiceNo"
                        type="text"
                        placeholder="Invoice number (scan or type), e.g. BR1/2627/00012"
                        class="min-w-0 flex-1 rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <PrimaryButton :disabled="!invoiceNo">Find</PrimaryButton>
                </form>

                <div v-if="sale" class="mt-4">
                    <p class="text-sm text-gray-600">
                        <span class="font-mono font-medium">{{ sale.invoice_no }}</span> · {{ dateTime(sale.sold_at) }} · {{ sale.customer }} · {{ money(sale.grand_total) }}
                    </p>
                    <table class="mt-3 w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase text-gray-500">
                            <tr><th class="py-1">Item</th><th class="py-1 text-right">Paid / pc</th><th class="py-1 text-center">Return</th><th class="py-1 text-center">Back to stock</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in lines" :key="l.id" class="border-t border-gray-100" :class="l.returnable <= 0 ? 'opacity-40' : ''">
                                <td class="py-2">{{ l.name }}<p class="text-xs text-gray-500">{{ l.sku }} · bought {{ qty(l.quantity) }}, returnable {{ qty(l.returnable) }}</p></td>
                                <td class="py-2 text-right tabular-nums">{{ money(l.unit_amount) }}</td>
                                <td class="py-2 text-center">
                                    <input
                                        v-model.number="l.return_qty"
                                        type="number"
                                        min="0"
                                        :max="l.returnable"
                                        :disabled="l.returnable <= 0"
                                        class="w-20 rounded-md border-gray-300 py-1 text-center text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </td>
                                <td class="py-2 text-center"><input v-model="l.restock" type="checkbox" class="rounded border-gray-300 text-indigo-600" title="Untick for damaged pieces" /></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <FormField label="Refund as">
                            <select v-model="refundMethod" class="block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </FormField>
                        <FormField label="Reason">
                            <TextInput v-model="reason" list="return-reasons" placeholder="Optional" />
                            <datalist id="return-reasons"><option v-for="r in reasons" :key="r" :value="r" /></datalist>
                        </FormField>
                    </div>
                    <p class="mt-3 text-right text-sm">Approx. refund <span class="text-lg font-semibold">{{ money(total) }}</span></p>
                </div>

                <p v-if="error" class="mt-3 rounded-md bg-red-50 p-2 text-sm text-red-700">{{ error }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="emit('close')">Cancel</SecondaryButton>
                    <PrimaryButton type="button" :disabled="!sale || total <= 0 || busy" @click="submit">{{ busy ? 'Saving…' : 'Make credit note' }}</PrimaryButton>
                </div>
            </template>
        </div>
    </Modal>
</template>
