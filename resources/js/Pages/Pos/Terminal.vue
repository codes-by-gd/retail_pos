<script setup>
import FlashMessages from '@/Components/FlashMessages.vue';
import { usePermissions } from '@/composables/usePermissions';
import BillDiscountModal from '@/Pos/BillDiscountModal.vue';
import CartLine from '@/Pos/CartLine.vue';
import CashModal from '@/Pos/CashModal.vue';
import CustomerPicker from '@/Pos/CustomerPicker.vue';
import HeldBillsModal from '@/Pos/HeldBillsModal.vue';
import { apiError, printUrl } from '@/Pos/helpers';
import LineEditModal from '@/Pos/LineEditModal.vue';
import LockScreen from '@/Pos/LockScreen.vue';
import PaymentModal from '@/Pos/PaymentModal.vue';
import ProductSearch from '@/Pos/ProductSearch.vue';
import ReturnModal from '@/Pos/ReturnModal.vue';
import { useCartStore } from '@/stores/cart';
import { money } from '@/utils/format';
import {
    ArrowLeftIcon,
    ArrowUturnLeftIcon,
    BanknotesIcon,
    CheckCircleIcon,
    LockClosedIcon,
    PauseCircleIcon,
    PrinterIcon,
    ReceiptPercentIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    session: { type: Object, required: true },
    walkIn: { type: Object, required: true },
    config: { type: Object, required: true },
    heldCount: { type: Number, default: 0 },
    lastSale: { type: Object, default: null },
    lockUsers: { type: Array, default: () => [] },
});

const page = usePage();
const { can } = usePermissions();
const cart = useCartStore();
const walkInCustomer = { ...props.walkIn, is_walk_in: true, price_type: 'retail', discount_percent: 0, balance: 0, credit_limit: 0, state_code: null };

cart.config = { round_off: props.config.round_off, branch_state: props.config.branch_state };
if (!cart.customer) cart.setCustomer(walkInCustomer);

const search = ref(null);
const customerPicker = ref(null);
const editing = ref(null);
const modal = ref(null); // 'pay' | 'discount' | 'returns' | 'held' | 'cash'
const locked = ref(false);
const paying = ref(false);
const payError = ref('');
const exchangeCredits = ref([]);
const completed = ref(null);
const held = ref(props.heldCount);
const last = ref(props.lastSale);
const cashier = ref(page.props.auth.user.name);
const toast = ref('');

const totals = computed(() => cart.preview.totals);
const anyModal = computed(() => !!modal.value || !!editing.value || locked.value || !!completed.value);

const flash = (msg) => {
    toast.value = msg;
    setTimeout(() => (toast.value = ''), 4000);
};

const addItem = (variant) => {
    if (variant.track_stock && variant.stock <= 0) flash(`${variant.name}: no stock recorded at this branch.`);
    cart.add(variant);
};

/* ---------- payment ---------- */
const openPay = () => {
    if (cart.isEmpty) return flash('Add items to the bill first.');
    if (cart.items.some((i) => !(Number(i.quantity) > 0))) return flash('Every line needs a quantity.');
    payError.value = '';
    modal.value = 'pay';
};

const completeSale = async (payments) => {
    paying.value = true;
    payError.value = '';
    try {
        const { data } = await axios.post(route('pos.checkout'), cart.checkoutPayload(payments));
        if (cart.heldCartId) held.value = Math.max(0, held.value - 1);
        completed.value = data.sale;
        last.value = { id: data.sale.id, invoice_no: data.sale.invoice_no, grand_total: data.sale.grand_total };
        modal.value = null;
        exchangeCredits.value = [];
        cart.clear(walkInCustomer);
        if (props.config.print_after_sale) printUrl(data.sale.print_url, props.config.receipt_size);
    } catch (e) {
        payError.value = apiError(e);
        if (e?.response?.status === 409) router.reload();
    } finally {
        paying.value = false;
    }
};

const newSale = () => {
    completed.value = null;
    setTimeout(() => search.value?.focus(), 50);
};

/* ---------- hold / recall ---------- */
const hold = async () => {
    if (cart.isEmpty) return flash('Nothing to hold.');
    const label = cart.customer?.is_walk_in
        ? `Bill ${new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })} · ${cart.items.length} items`
        : `${cart.customer.name} · ${cart.items.length} items`;
    try {
        if (cart.heldCartId) await axios.delete(route('pos.held.destroy', cart.heldCartId)).catch(() => {});
        const { data } = await axios.post(route('pos.held.store'), { label, payload: cart.snapshot() });
        held.value = data.count;
        cart.clear(walkInCustomer);
        flash(`Bill held: ${label}`);
    } catch (e) {
        flash(apiError(e));
    }
};

const recall = async (h) => {
    if (!cart.isEmpty && !window.confirm('Replace the current bill with the held bill?')) return;
    cart.restore(h.payload, h.id);
    modal.value = null;
    // Refresh prices, GST and stock in case they changed while the bill was parked.
    const ids = cart.items.map((i) => i.variant_id);
    if (!ids.length) return;
    const { data } = await axios.get(route('variants.search'), { params: { ids } });
    const fresh = Object.fromEntries(data.data.map((v) => [v.id, v]));
    cart.items = cart.items.filter((i) => fresh[i.variant_id]);
    cart.items.forEach((i) => {
        const v = fresh[i.variant_id];
        Object.assign(i, {
            selling_price: v.selling_price, wholesale_price: v.wholesale_price, mrp: v.mrp, price_includes_tax: v.price_includes_tax,
            gst_rate: v.gst_rate, gst_cess: v.gst_cess, gst_threshold: v.gst_threshold, gst_rate_above: v.gst_rate_above, stock: v.stock,
        });
    });
};

const clearBill = () => {
    if (cart.isEmpty || window.confirm('Clear this bill?')) {
        cart.clear(walkInCustomer);
        exchangeCredits.value = [];
    }
};

/* ---------- returns ---------- */
const onExchange = (credit) => {
    exchangeCredits.value = [...exchangeCredits.value, credit];
    flash(`Exchange credit ${money(credit.total)} will be applied when you press Pay.`);
};

/* ---------- lock ---------- */
const onUnlocked = (user) => {
    locked.value = false;
    cashier.value = user.name;
    if (user.id !== page.props.auth.user.id) router.reload({ only: ['auth', 'lockUsers'] });
    setTimeout(() => search.value?.focus(), 50);
};

/* ---------- keyboard ---------- */
const onKey = (e) => {
    if (locked.value) return;
    if (completed.value && (e.key === 'Enter' || e.key === 'Escape')) {
        e.preventDefault();
        return newSale();
    }
    const keys = {
        F2: () => search.value?.focus(),
        F4: () => customerPicker.value?.show(),
        F6: () => can('pos.discount') && (modal.value = 'discount'),
        F8: () => hold(),
        F9: () => openPay(),
        F10: () => can('pos.returns') && (modal.value = 'returns'),
    };
    if (keys[e.key] && (!anyModal.value || e.key === 'F2')) {
        e.preventDefault();
        keys[e.key]();
    }
};
// Keep the cursor in the search box so a barcode scanner always works (after printing, window switches…).
const refocus = () => {
    if (!anyModal.value) setTimeout(() => search.value?.focus(), 30);
};
onMounted(() => {
    window.addEventListener('keydown', onKey);
    window.addEventListener('focus', refocus);
    window.addEventListener('pos:refocus', refocus);
    search.value?.focus();
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('focus', refocus);
    window.removeEventListener('pos:refocus', refocus);
});
</script>

<template>
    <Head title="POS" />
    <div class="flex h-screen flex-col bg-gray-100">
        <!-- top bar -->
        <header class="flex h-14 shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-3">
            <Link :href="route('dashboard')" class="rounded-md p-2 text-gray-500 hover:bg-gray-100" title="Back to dashboard"><ArrowLeftIcon class="h-5 w-5" /></Link>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-900">{{ $page.props.app.business_name }} · {{ $page.props.branch?.current?.name }}</p>
                <p class="truncate text-xs text-gray-500">{{ session.register }} · Cashier: {{ cashier }}</p>
            </div>
            <div class="ml-auto flex items-center gap-1">
                <button v-if="can('pos.returns')" type="button" class="top-btn" @click="modal = 'returns'"><ArrowUturnLeftIcon class="h-5 w-5" /><span>Returns <kbd>F10</kbd></span></button>
                <button type="button" class="top-btn" @click="modal = 'held'">
                    <PauseCircleIcon class="h-5 w-5" /><span>Held</span>
                    <span v-if="held" class="rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white">{{ held }}</span>
                </button>
                <button v-if="can('pos.register.manage')" type="button" class="top-btn" @click="modal = 'cash'"><BanknotesIcon class="h-5 w-5" /><span>Cash in/out</span></button>
                <button v-if="last" type="button" class="top-btn" :title="`Reprint ${last.invoice_no}`" @click="printUrl(route('sales.print', last.id), config.receipt_size)">
                    <PrinterIcon class="h-5 w-5" /><span>Last bill</span>
                </button>
                <Link v-if="can('pos.register.manage')" :href="route('register-sessions.show', session.id)" class="top-btn"><ReceiptPercentIcon class="h-5 w-5" /><span>Close register</span></Link>
                <button type="button" class="top-btn" title="Lock / switch cashier" @click="locked = true"><LockClosedIcon class="h-5 w-5" /></button>
            </div>
        </header>

        <div class="flex min-h-0 flex-1 flex-col gap-3 p-3 lg:flex-row">
            <!-- left: search -->
            <section class="flex min-h-0 flex-1 flex-col rounded-xl bg-white p-4 shadow-sm lg:basis-3/5">
                <ProductSearch ref="search" :beep-on-scan="config.beep_on_scan" @add="addItem" />
            </section>

            <!-- right: bill -->
            <section class="flex min-h-0 flex-col rounded-xl bg-white shadow-sm lg:basis-2/5">
                <div class="border-b border-gray-100"><CustomerPicker ref="customerPicker" :walk-in="walkIn" /></div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <CartLine v-for="item in cart.items" :key="item.key" :item="item" @edit="editing = $event" />
                    <p v-if="cart.isEmpty" class="p-8 text-center text-sm text-gray-400">No items yet — scan a tag to start the bill.</p>
                </div>

                <div v-if="exchangeCredits.length" class="border-t border-green-100 bg-green-50 px-4 py-2 text-xs text-green-800">
                    Exchange credit to apply: {{ exchangeCredits.map((c) => `${c.credit_note_no} (${money(c.total)})`).join(', ') }}
                </div>

                <!-- totals -->
                <dl class="space-y-0.5 border-t border-gray-200 px-4 py-3 text-sm">
                    <div class="flex justify-between text-gray-600"><dt>Items {{ cart.items.length }} · Qty {{ cart.count }}</dt><dd class="tabular-nums">{{ money(totals.subtotal) }}</dd></div>
                    <div v-if="totals.line_discount_total" class="flex justify-between text-green-700"><dt>Item discounts</dt><dd class="tabular-nums">−{{ money(totals.line_discount_total) }}</dd></div>
                    <div v-if="totals.bill_discount_total" class="flex justify-between text-green-700">
                        <dt>Bill discount<span v-if="cart.billDiscount.type === 'percent'"> ({{ cart.billDiscount.value }}%)</span></dt>
                        <dd class="tabular-nums">−{{ money(totals.bill_discount_total) }}</dd>
                    </div>
                    <div class="flex justify-between text-xs text-gray-500">
                        <dt>Taxable {{ money(totals.taxable_total) }} · {{ cart.interState ? `IGST ${money(totals.igst_total)}` : `CGST ${money(totals.cgst_total)} + SGST ${money(totals.sgst_total)}` }}</dt>
                        <dd v-if="totals.round_off" class="tabular-nums">Round off {{ money(totals.round_off) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between pt-1">
                        <dt class="text-base font-semibold text-gray-900">Total</dt>
                        <dd class="text-3xl font-bold tabular-nums text-gray-900">{{ money(totals.grand_total) }}</dd>
                    </div>
                </dl>

                <div class="grid grid-cols-4 gap-2 border-t border-gray-100 p-3">
                    <button type="button" class="act-btn" :disabled="!can('pos.discount') || cart.isEmpty" @click="modal = 'discount'"><ReceiptPercentIcon class="h-5 w-5" />Discount<kbd>F6</kbd></button>
                    <button type="button" class="act-btn" :disabled="cart.isEmpty" @click="hold"><PauseCircleIcon class="h-5 w-5" />Hold<kbd>F8</kbd></button>
                    <button type="button" class="act-btn" :disabled="cart.isEmpty" @click="clearBill"><TrashIcon class="h-5 w-5" />Clear</button>
                    <button type="button" class="col-span-1 flex flex-col items-center justify-center rounded-lg bg-indigo-600 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50"
                        :disabled="cart.isEmpty" @click="openPay">
                        Pay<kbd class="!border-indigo-400 !bg-indigo-500 !text-indigo-100">F9</kbd>
                    </button>
                </div>
            </section>
        </div>

        <!-- toast -->
        <div v-if="toast" class="fixed bottom-4 left-1/2 z-40 -translate-x-1/2 rounded-lg bg-gray-900 px-4 py-2 text-sm text-white shadow-lg">{{ toast }}</div>

        <!-- sale completed -->
        <div v-if="completed" class="fixed inset-0 z-40 flex items-center justify-center bg-gray-900/60 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-8 text-center shadow-xl">
                <CheckCircleIcon class="mx-auto h-14 w-14 text-green-500" />
                <p class="mt-2 font-mono text-sm text-gray-500">{{ completed.invoice_no }}</p>
                <p class="text-lg font-semibold text-gray-900">{{ money(completed.grand_total) }} · {{ completed.customer }}</p>
                <p v-if="completed.change_given > 0" class="mt-4 rounded-lg bg-green-50 py-3 text-3xl font-bold text-green-700">Change {{ money(completed.change_given) }}</p>
                <p v-if="completed.account_amount > 0" class="mt-3 text-sm text-amber-700">{{ money(completed.account_amount) }} added to the customer's account.</p>
                <div class="mt-6 grid grid-cols-2 gap-2">
                    <button type="button" class="act-btn !flex-row" @click="printUrl(completed.print_url, config.receipt_size)"><PrinterIcon class="h-5 w-5" /> Receipt</button>
                    <button type="button" class="act-btn !flex-row" @click="printUrl(completed.print_url, 'a4')"><PrinterIcon class="h-5 w-5" /> A4 invoice</button>
                </div>
                <button type="button" class="mt-3 w-full rounded-lg bg-indigo-600 py-3 font-semibold text-white hover:bg-indigo-500" @click="newSale">New sale (Enter)</button>
            </div>
        </div>

        <PaymentModal :show="modal === 'pay'" :config="config" :processing="paying" :error="payError" :exchange-credits="exchangeCredits" @close="modal = null" @complete="completeSale" />
        <BillDiscountModal :show="modal === 'discount'" @close="modal = null" />
        <ReturnModal :show="modal === 'returns'" :config="config" @close="modal = null" @exchange="onExchange" />
        <HeldBillsModal :show="modal === 'held'" @close="modal = null" @recall="recall" @count="held = $event" />
        <CashModal :show="modal === 'cash'" @close="modal = null" />
        <LineEditModal :item="editing" @close="editing = null" />
        <LockScreen v-if="locked" :users="lockUsers" :current-user-id="$page.props.auth.user.id" @unlocked="onUnlocked" />
        <FlashMessages />
    </div>
</template>

<style scoped>
.top-btn {
    @apply flex items-center gap-1.5 rounded-md px-2.5 py-2 text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900;
}
.top-btn span {
    @apply hidden xl:inline;
}
.act-btn {
    @apply flex flex-col items-center justify-center gap-0.5 rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40;
}
kbd {
    @apply rounded border border-gray-300 bg-gray-50 px-1 font-mono text-[10px] text-gray-500;
}
</style>
