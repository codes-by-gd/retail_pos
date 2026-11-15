<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/utils/format';
import PaymentFormModal from '@/Components/PaymentFormModal.vue';
import { BanknotesIcon, PencilSquareIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { router, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    kind: { type: String, required: true },
    contact: { type: Object, required: true },
    statement: { type: Object, required: true },
    period: { type: Object, required: true },
    unpaidBills: { type: Array, default: () => [] },
    registers: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    loyalty: { type: Object, default: null },
});
const adjusting = ref(false);
const showAllPoints = ref(false);
const pointsForm = useForm({ points: '', note: '' });
const adjustPoints = () => pointsForm.post(route('customers.loyalty.adjust', props.contact.id), { preserveScroll: true, onSuccess: () => { adjusting.value = false; pointsForm.reset(); } });
const paying = ref(false);
const direction = computed(() => (props.kind === 'customer' ? 'in' : 'out'));
const unpaidTotal = computed(() => props.unpaidBills.reduce((s, b) => s + b.due, 0));
const suggested = computed(() => Math.max(unpaidTotal.value, direction.value === 'in' ? props.contact.balance : -props.contact.balance, 0));

const { can } = usePermissions();
const plural = computed(() => `${props.kind}s`);
const deleting = ref(false);

const period = reactive({ from: props.period.from ?? '', to: props.period.to ?? '' });
watch(period, () => {
    const query = Object.fromEntries(Object.entries(period).filter(([, v]) => v));
    router.get(route(`${plural.value}.show`, props.contact.id), query, { preserveState: true, preserveScroll: true, replace: true });
});

const drcr = (v) => (v > 0 ? `${money(v)} Dr` : v < 0 ? `${money(-v)} Cr` : money(0));
const balanceText = computed(() =>
    props.contact.balance > 0 ? 'They owe us' : props.contact.balance < 0 ? 'We owe them' : 'Settled',
);
const typeLabel = { customer: 'Customer', supplier: 'Supplier', both: 'Customer & supplier' };

const printPage = () => window.print();
const destroy = () => router.delete(route(`${plural.value}.destroy`, props.contact.id), { onFinish: () => (deleting.value = false) });
</script>

<template>
    <AppLayout :title="contact.name">
        <PageHeader :title="contact.name" :description="`${contact.code} · ${typeLabel[contact.type]}${contact.company_name ? ' · ' + contact.company_name : ''}`">
            <template #actions>
                <button
                    type="button"
                    class="no-print inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    @click="printPage"
                >
                    <PrinterIcon class="h-4 w-4" /> Print statement
                </button>
                <button
                    v-if="can('payments.manage') && !contact.is_walk_in"
                    type="button"
                    class="no-print inline-flex items-center gap-2 rounded-md border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 shadow-sm hover:bg-indigo-100"
                    @click="paying = true"
                >
                    <BanknotesIcon class="h-4 w-4" /> {{ kind === 'customer' ? 'Receive payment' : 'Pay supplier' }}
                </button>
                <DangerButton v-if="can(`${plural}.manage`) && !contact.is_walk_in" class="no-print" @click="deleting = true">Delete</DangerButton>
                <ButtonLink v-if="can(`${plural}.manage`)" :href="route(`${plural}.edit`, contact.id)" class="no-print">
                    <PencilSquareIcon class="h-4 w-4" /> Edit
                </ButtonLink>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">Balance</p>
                <p
                    class="mt-1 text-3xl font-semibold tabular-nums"
                    :class="contact.balance > 0 ? 'text-amber-700' : contact.balance < 0 ? 'text-indigo-700' : 'text-gray-900'"
                >
                    {{ money(Math.abs(contact.balance)) }}
                </p>
                <p class="text-sm text-gray-500">{{ balanceText }}</p>
                <div v-if="contact.type !== 'supplier'" class="mt-4 border-t border-gray-100 pt-3 text-sm">
                    <p class="text-gray-500">Credit limit</p>
                    <p class="font-medium">{{ Number(contact.credit_limit) > 0 ? money(contact.credit_limit) : 'No credit sales' }}</p>
                </div>
                <div v-if="contact.payment_terms_days" class="mt-3 text-sm">
                    <p class="text-gray-500">Payment terms</p>
                    <p class="font-medium">{{ contact.payment_terms_days }} days</p>
                </div>
            </div>

            <Card class="lg:col-span-2" title="Details">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-gray-500">Phone</dt><dd class="font-medium tabular-nums">{{ contact.phone ?? '—' }}<span v-if="contact.alt_phone" class="block text-gray-500">{{ contact.alt_phone }}</span></dd></div>
                    <div><dt class="text-gray-500">Email</dt><dd class="break-all font-medium">{{ contact.email ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">GSTIN</dt><dd class="font-mono font-medium">{{ contact.gstin ?? '—' }}</dd></div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Address</dt>
                        <dd class="font-medium">
                            {{ [contact.address, contact.city, contact.pincode].filter(Boolean).join(', ') || '—' }}
                            <span v-if="contact.state_name" class="block font-normal text-gray-500">{{ contact.state_name }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="flex flex-wrap gap-1">
                            <Badge :color="contact.is_active ? 'green' : 'gray'">{{ contact.is_active ? 'Active' : 'Inactive' }}</Badge>
                            <Badge v-if="contact.is_walk_in" color="indigo">Built-in walk-in</Badge>
                        </dd>
                    </div>
                    <div v-if="contact.group">
                        <dt class="text-gray-500">Group</dt>
                        <dd class="font-medium">
                            {{ contact.group.name }}
                            <span class="block font-normal text-gray-500">
                                {{ contact.group.price_type === 'wholesale' ? 'Wholesale prices' : 'Retail prices' }}{{ Number(contact.group.discount_percent) ? `, ${Number(contact.group.discount_percent)}% off` : '' }}
                            </span>
                        </dd>
                    </div>
                    <div v-if="contact.date_of_birth"><dt class="text-gray-500">Birthday</dt><dd class="font-medium">{{ date(contact.date_of_birth) }}</dd></div>
                    <div v-if="contact.anniversary"><dt class="text-gray-500">Anniversary</dt><dd class="font-medium">{{ date(contact.anniversary) }}</dd></div>
                    <div v-if="contact.notes" class="sm:col-span-3"><dt class="text-gray-500">Notes</dt><dd class="whitespace-pre-line">{{ contact.notes }}</dd></div>
                </dl>
            </Card>
        </div>

        <Card v-if="loyalty" class="mt-6" title="Loyalty points" :padded="false">
            <template #actions>
                <SecondaryButton v-if="can('loyalty.adjust')" type="button" @click="adjusting = !adjusting">Adjust points</SecondaryButton>
            </template>
            <div class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-3">
                <div><p class="text-xs text-gray-500">Balance</p><p class="text-2xl font-semibold tabular-nums text-gray-900">★ {{ loyalty.balance }}</p><p class="text-xs text-gray-500">worth {{ money(loyalty.value) }}</p></div>
                <div v-if="loyalty.next_expiry"><p class="text-xs text-gray-500">Expiring next</p><p class="font-medium text-amber-700">{{ loyalty.next_expiry.points }} pts on {{ date(loyalty.next_expiry.date) }}</p></div>
                <div v-if="!loyalty.enabled" class="text-xs text-amber-700">Loyalty is switched off in Settings; points can't be earned or used.</div>
            </div>
            <form v-if="adjusting" class="grid grid-cols-1 gap-3 border-b border-gray-100 bg-gray-50 p-5 sm:grid-cols-[8rem_1fr_auto] sm:items-start" @submit.prevent="adjustPoints">
                <div><TextInput v-model="pointsForm.points" type="number" step="1" placeholder="+50 / −20" aria-label="Points" /><InputError :message="pointsForm.errors.points" /></div>
                <div><TextInput v-model="pointsForm.note" placeholder="Reason (goodwill, correction…)" aria-label="Reason" /><InputError :message="pointsForm.errors.note" /></div>
                <PrimaryButton :disabled="pointsForm.processing">Save</PrimaryButton>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="t in showAllPoints ? loyalty.history : loyalty.history.slice(0, 6)" :key="t.id">
                            <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ date(t.date) }}</td>
                            <td class="px-4 py-2">{{ t.type }}<p class="text-xs text-gray-500">{{ t.note }}<span v-if="t.expires_at"> · expires {{ date(t.expires_at) }}</span></p></td>
                            <td class="px-4 py-2 text-right">
                                <Link v-if="t.sale_id" :href="route('sales.show', t.sale_id)" class="text-xs text-indigo-600 hover:text-indigo-800">bill</Link>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums" :class="t.points < 0 ? 'text-red-700' : 'text-green-700'">{{ t.points > 0 ? '+' : '' }}{{ t.points }}</td>
                        </tr>
                        <tr v-if="!loyalty.history.length"><td class="px-4 py-6 text-center text-gray-500">No points yet — they are earned on every bill.</td></tr>
                    </tbody>
                </table>
            </div>
            <button v-if="loyalty.history.length > 6" type="button" class="block w-full border-t border-gray-100 py-2 text-center text-xs text-indigo-600 hover:bg-gray-50" @click="showAllPoints = !showAllPoints">
                {{ showAllPoints ? 'Show less' : `Show all ${loyalty.history.length} recent entries` }}
            </button>
        </Card>

        <Card v-if="unpaidBills.length" class="mt-6" :title="`Unpaid bills (${unpaidBills.length})`" :description="`Total due ${money(unpaidTotal)}`" :padded="false">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="b in unpaidBills" :key="b.id">
                        <td class="px-4 py-2"><Link :href="b.url" class="font-mono text-indigo-600 hover:text-indigo-800">{{ b.number }}</Link></td>
                        <td class="px-4 py-2 text-gray-500">{{ date(b.date) }}<span v-if="b.overdue" class="ml-2 rounded bg-red-50 px-1.5 text-xs text-red-700">overdue</span></td>
                        <td class="px-4 py-2 text-right text-gray-500">of {{ money(b.total) }}</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ money(b.due) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card class="mt-6" title="Account statement" :padded="false">
            <template #actions>
                <div class="no-print flex items-center gap-2">
                    <TextInput v-model="period.from" type="date" class="!w-36 !py-1.5" aria-label="From" />
                    <span class="text-gray-400">–</span>
                    <TextInput v-model="period.to" type="date" class="!w-36 !py-1.5" aria-label="To" />
                </div>
            </template>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Date</th>
                            <th class="px-4 py-3 text-left">Particulars</th>
                            <th class="px-4 py-3 text-right">Debit</th>
                            <th class="px-4 py-3 text-right">Credit</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="period.from" class="bg-gray-50/50">
                            <td class="px-4 py-2 text-gray-500">{{ date(period.from) }}</td>
                            <td class="px-4 py-2 font-medium" colspan="3">Balance brought forward</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ drcr(Number(statement.opening)) }}</td>
                        </tr>
                        <tr v-for="r in statement.rows" :key="r.id">
                            <td class="whitespace-nowrap px-4 py-2 text-gray-600">{{ date(r.date) }}</td>
                            <td class="px-4 py-2">
                                {{ r.type_label }}<span v-if="r.reference" class="font-mono text-xs text-gray-500"> · {{ r.reference }}</span>
                                <p v-if="r.note && r.note !== r.type_label" class="text-xs text-gray-500">{{ r.note }}</p>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.debit ? money(r.debit) : '' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.credit ? money(r.credit) : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ drcr(r.balance) }}</td>
                        </tr>
                        <tr v-if="!statement.rows.length">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                No transactions{{ period.from || period.to ? ' in this period' : ' yet' }}. Sales, purchases and payments will appear here.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="statement.rows.length" class="border-t border-gray-200 bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2" colspan="2">Total / closing balance</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ money(statement.debit) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ money(statement.credit) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ drcr(Number(statement.closing)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>

        <PaymentFormModal
            v-if="!contact.is_walk_in"
            :show="paying"
            :contact="{ id: contact.id, name: contact.company_name || contact.name }"
            :direction="direction"
            :suggested="suggested"
            :methods="paymentMethods"
            :registers="registers"
            @close="paying = false"
        />

        <ConfirmModal
            :show="deleting"
            :title="`Delete ${contact.name}?`"
            message="Only contacts without transactions can be deleted. Otherwise, deactivate them instead."
            @confirm="destroy"
            @close="deleting = false"
        />
    </AppLayout>
</template>
