<script setup>
import ScanButton from '@/Components/ScanButton.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useCartStore } from '@/stores/cart';
import { money } from '@/utils/format';
import { BanknotesIcon, CreditCardIcon, DevicePhoneMobileIcon, GiftIcon, ReceiptRefundIcon, UserIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';
import { apiError } from './helpers';
import { play } from '@/utils/sounds';

const props = defineProps({
    show: { type: Boolean, default: false },
    config: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    error: { type: String, default: '' },
    exchangeCredits: { type: Array, default: () => [] }, // credit notes created in this sale's return flow
});
const emit = defineEmits(['close', 'complete']);

const cart = useCartStore();
const payments = ref([]); // { method, amount, reference, sale_return_id, label }
const cashInput = ref(null);
const root = ref(null);
const cnNumber = ref('');
const localError = ref('');

const total = computed(() => cart.total);
const paid = computed(() => payments.value.reduce((s, p) => s + Number(p.amount || 0), 0));
const remaining = computed(() => Math.max(0, round(total.value - paid.value)));
const change = computed(() => Math.max(0, round(paid.value - total.value)));
const round = (v) => Math.round(v * 100) / 100;

const canAccount = computed(() => cart.customer && !cart.customer.is_walk_in);
const accountRoom = computed(() => (canAccount.value ? round(Number(cart.customer.credit_limit || 0) - Number(cart.customer.balance || 0)) : 0));

const labels = { cash: 'Cash', card: 'Card', upi: 'UPI', account: 'On account', exchange: 'Exchange credit', points: 'Loyalty points' };

// ---- loyalty points (a payment worth points × point value, limited to a % of the bill) ----
const loyalty = computed(() => props.config.loyalty ?? { enabled: false });
const pointsRow = computed(() => payments.value.find((p) => p.method === 'points'));
const maxPoints = computed(() => {
    if (!loyalty.value.enabled || !canAccount.value) return 0;
    const byBill = Math.floor((total.value * loyalty.value.max_redeem_percent) / 100 / loyalty.value.point_value + 1e-9);
    const max = Math.min(Number(cart.customer.points || 0), byBill);
    return max >= loyalty.value.min_redeem ? max : 0;
});
const addPoints = () => {
    localError.value = '';
    if (pointsRow.value) return;
    const otherPaid = paid.value;
    const fit = Math.floor(Math.max(0, total.value - otherPaid) / loyalty.value.point_value + 1e-9);
    const pts = Math.min(maxPoints.value, fit);
    if (pts < loyalty.value.min_redeem) return (localError.value = `At least ${loyalty.value.min_redeem} points are needed and the bill must still be unpaid.`);
    play('payment');
    payments.value.push({ method: 'points', points: pts, amount: round(pts * loyalty.value.point_value), reference: '', sale_return_id: null, label: 'Loyalty points' });
};
const setPoints = (p, value) => {
    const pts = Math.max(0, Math.min(maxPoints.value, Math.floor(Number(value) || 0)));
    p.points = pts;
    p.amount = round(pts * loyalty.value.point_value);
};

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        localError.value = '';
        cnNumber.value = '';
        // Exchange credits from a return just made go in first.
        payments.value = props.exchangeCredits.map((r) => ({
            method: 'exchange', amount: r.total, reference: r.credit_note_no, sale_return_id: r.id, label: `Exchange ${r.credit_note_no}`,
        }));
        await nextTick();
        // Focus inside the dialog so Enter completes the sale even before a payment row exists.
        (cashInput.value ?? root.value)?.focus();
    },
);

const add = (method, amount = remaining.value) => {
    if (amount <= 0 && method !== 'cash') return;
    play('payment');
    const existing = payments.value.find((p) => p.method === method && !p.sale_return_id);
    if (existing) existing.amount = round(Number(existing.amount) + amount);
    else payments.value.push({ method, amount: round(amount), reference: '', sale_return_id: null, label: labels[method] });
};
const cashTender = (note) => add('cash', note);
const exactCash = () => add('cash', remaining.value);
const addAccount = () => {
    if (remaining.value <= 0) return (localError.value = 'The bill is already fully paid.');
    const amount = Math.min(remaining.value, Math.max(0, accountRoom.value));
    if (amount <= 0) return (localError.value = 'No credit available for this customer.');
    add('account', amount);
};
const addExchange = async () => {
    localError.value = '';
    try {
        const { data } = await axios.get(route('pos.exchange-credit'), { params: { credit_note_no: cnNumber.value } });
        if (payments.value.some((p) => p.sale_return_id === data.return.id)) return (localError.value = 'Already added.');
        payments.value.push({ method: 'exchange', amount: data.return.total, reference: data.return.credit_note_no, sale_return_id: data.return.id, label: `Exchange ${data.return.credit_note_no}` });
        cnNumber.value = '';
    } catch (e) {
        localError.value = apiError(e);
    }
};

watch([localError, () => props.error], ([a, b]) => (a || b) && play('error'));

const complete = () => {
    localError.value = '';
    if (remaining.value > 0) return (localError.value = `Still ${money(remaining.value)} to collect.`);
    const nonChange = payments.value.filter((p) => !['cash', 'exchange'].includes(p.method)).reduce((s, p) => s + Number(p.amount), 0);
    if (nonChange > total.value + 0.001) return (localError.value = 'Card, UPI, points and on-account amounts cannot be more than the bill.');
    emit('complete', payments.value.filter((p) => Number(p.amount) > 0).map(({ method, amount, reference, sale_return_id }) => ({ method, amount: Number(amount), reference: reference || null, sale_return_id })));
};

defineExpose({ complete });
</script>

<template>
    <Modal :show="show" max-width="2xl" :closeable="!processing" @close="emit('close')">
        <div ref="root" tabindex="-1" class="grid grid-cols-1 gap-0 outline-hidden sm:grid-cols-2" @keydown.enter.prevent="complete">
            <!-- left: totals & tenders -->
            <div class="bg-gray-50 p-6">
                <p class="text-sm text-gray-500">Amount to pay</p>
                <p class="text-4xl font-bold tabular-nums text-gray-900">{{ money(total) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ cart.count }} items · {{ cart.customer?.name }}</p>

                <div class="mt-5 space-y-2">
                    <!-- Two-line card so long labels / references never push the remove button out of view -->
                    <div v-for="(p, i) in payments" :key="i" class="rounded-md bg-white p-2 shadow-xs">
                        <div class="flex items-start justify-between gap-2">
                            <span class="min-w-0 break-words text-sm font-medium text-gray-700">{{ p.label }}</span>
                            <button type="button" class="shrink-0 rounded-sm p-0.5 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Remove" @click="payments.splice(i, 1)">
                                <XMarkIcon class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="mt-1.5 flex gap-2">
                            <label v-if="p.method === 'points'" class="flex min-w-0 flex-1 items-center gap-1 text-xs text-gray-500">
                                <input
                                    :value="p.points"
                                    type="number"
                                    step="1"
                                    :min="loyalty.min_redeem"
                                    :max="maxPoints"
                                    aria-label="Points"
                                    class="w-24 rounded-md border-gray-300 py-1 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500"
                                    @input="setPoints(p, $event.target.value)"
                                    @focus="$event.target.select()"
                                />
                                pts = <span class="font-medium tabular-nums text-gray-800">{{ money(p.amount) }}</span>
                            </label>
                            <input
                                v-else
                                v-model.number="p.amount"
                                :ref="(el) => p.method === 'cash' && (cashInput = el)"
                                type="number"
                                step="0.01"
                                min="0"
                                :readonly="p.method === 'exchange'"
                                aria-label="Amount"
                                class="min-w-0 flex-1 rounded-md border-gray-300 py-1 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500 read-only:bg-gray-50"
                                @focus="$event.target.select()"
                            />
                            <input
                                v-if="['card', 'upi'].includes(p.method)"
                                v-model="p.reference"
                                type="text"
                                :placeholder="p.method === 'upi' ? 'UPI ref no.' : 'Card last 4 / slip'"
                                class="min-w-0 flex-1 rounded-md border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                    </div>
                </div>

                <dl class="mt-5 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Received</dt><dd class="tabular-nums">{{ money(paid) }}</dd></div>
                    <div v-if="remaining > 0" class="flex justify-between font-semibold text-red-600"><dt>Still to pay</dt><dd class="tabular-nums">{{ money(remaining) }}</dd></div>
                    <div v-if="change > 0" class="flex justify-between text-2xl font-bold text-green-700"><dt>Change</dt><dd class="tabular-nums">{{ money(change) }}</dd></div>
                </dl>
            </div>

            <!-- right: methods -->
            <div class="space-y-3 p-6">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="pay-btn" @click="exactCash"><BanknotesIcon class="h-5 w-5" /> Cash (exact)</button>
                    <button v-if="config.accept_upi" type="button" class="pay-btn" @click="add('upi')"><DevicePhoneMobileIcon class="h-5 w-5" /> UPI</button>
                    <button v-if="config.accept_card" type="button" class="pay-btn" @click="add('card')"><CreditCardIcon class="h-5 w-5" /> Card</button>
                    <button v-if="canAccount" type="button" class="pay-btn" :disabled="accountRoom <= 0" :title="`Available: ${money(accountRoom)}`" @click="addAccount">
                        <UserIcon class="h-5 w-5" /> On account
                    </button>
                    <button v-if="loyalty.enabled && canAccount" type="button" class="pay-btn" :disabled="!maxPoints || !!pointsRow"
                        :title="maxPoints ? `Up to ${maxPoints} points (${money(maxPoints * loyalty.point_value)})` : `Needs at least ${loyalty.min_redeem} points`" @click="addPoints">
                        <GiftIcon class="h-5 w-5" /> Points <span class="text-xs font-normal text-gray-500">{{ cart.customer.points || 0 }}</span>
                    </button>
                </div>
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">Cash notes received</p>
                    <div class="grid grid-cols-4 gap-1.5">
                        <button v-for="n in [100, 200, 500, 2000]" :key="n" type="button" class="rounded-md border border-gray-300 py-1.5 text-sm hover:bg-gray-50" @click="cashTender(n)">
                            ₹{{ n }}
                        </button>
                    </div>
                </div>
                <div v-if="canAccount" class="text-xs text-gray-500">
                    Account: {{ cart.customer.balance > 0 ? `owes ${money(cart.customer.balance)}` : cart.customer.balance < 0 ? `credit ${money(-cart.customer.balance)}` : 'settled' }} ·
                    can use {{ money(Math.max(0, accountRoom)) }}
                </div>
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">Exchange credit note</p>
                    <div class="flex gap-2">
                        <input
                            v-model="cnNumber"
                            type="text"
                            placeholder="CNBR1/2627/…"
                            class="min-w-0 flex-1 rounded-md border-gray-300 py-1.5 text-sm uppercase focus:border-indigo-500 focus:ring-indigo-500"
                            @keydown.enter.prevent.stop="addExchange"
                        />
                        <ScanButton title="Scan the credit note" hint="Point at the barcode on the credit note" @detected="(c) => ((cnNumber = c), addExchange())" />
                        <SecondaryButton :disabled="!cnNumber" @click="addExchange"><ReceiptRefundIcon class="h-4 w-4" /></SecondaryButton>
                    </div>
                </div>

                <p v-if="localError || error" class="rounded-md bg-red-50 p-2 text-sm text-red-700">{{ localError || error }}</p>

                <PrimaryButton type="button" class="w-full !py-3 text-base" :disabled="processing || remaining > 0" @click="complete">
                    {{ processing ? 'Saving…' : 'Complete sale (Enter)' }}
                </PrimaryButton>
                <SecondaryButton class="w-full" :disabled="processing" @click="emit('close')">Back to bill</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
@reference "../../css/app.css";

.pay-btn {
    @apply flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-3 text-sm font-medium text-gray-800 shadow-xs hover:border-indigo-400 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-40;
}
</style>
