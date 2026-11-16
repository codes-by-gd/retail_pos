<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money } from '@/utils/format';
import { PrinterIcon } from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    session: { type: Object, required: true },
    summary: { type: Object, required: true },
    canClose: { type: Boolean, default: false },
});

const form = useForm({ counted_cash: '', closing_note: '' });
const diff = computed(() => (form.counted_cash === '' ? null : Number(form.counted_cash) - Number(props.summary.expected_cash)));
const close = () => form.post(route('register-sessions.close', props.session.id));
const printPage = () => window.print();
const refundLabels = { cash: 'Cash refunds', card: 'Card refunds', upi: 'UPI refunds', account: 'Store credit', exchange: 'Exchange credit' };
</script>

<template>
    <AppLayout :title="`Shift #${session.id}`">
        <PageHeader :title="`${session.register} · shift #${session.id}`" :description="`${session.branch} · opened ${dateTime(session.opened_at)} by ${session.opened_by}`">
            <template #actions>
                <button type="button" class="no-print inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50" @click="printPage">
                    <PrinterIcon class="h-4 w-4" /> Print Z-report
                </button>
                <ButtonLink v-if="session.status === 'open'" :href="route('pos.terminal')" variant="secondary" class="no-print">Back to POS</ButtonLink>
                <Badge :color="session.status === 'open' ? 'green' : 'gray'">{{ session.status === 'open' ? 'Open' : 'Closed' }}</Badge>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card title="Sales" class="lg:col-span-2">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-gray-500">Bills</dt><dd class="text-xl font-semibold">{{ summary.sales_count }}</dd></div>
                    <div><dt class="text-gray-500">Sales</dt><dd class="text-xl font-semibold tabular-nums">{{ money(summary.gross_sales) }}</dd></div>
                    <div><dt class="text-gray-500">Discounts</dt><dd class="text-xl font-semibold tabular-nums">{{ money(summary.discounts) }}</dd></div>
                    <div><dt class="text-gray-500">GST collected</dt><dd class="text-xl font-semibold tabular-nums">{{ money(summary.tax) }}</dd></div>
                </dl>
                <table class="mt-6 w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase text-gray-500"><tr><th class="py-1">Payment</th><th class="py-1 text-right">Count</th><th class="py-1 text-right">Amount</th></tr></thead>
                    <tbody>
                        <tr v-for="p in summary.payments" :key="p.method" class="border-t border-gray-100">
                            <td class="py-1.5">{{ p.label }}</td><td class="py-1.5 text-right">{{ p.count }}</td><td class="py-1.5 text-right tabular-nums">{{ money(p.total) }}</td>
                        </tr>
                        <tr v-if="Number(summary.change_given)" class="border-t border-gray-100 text-gray-500">
                            <td class="py-1.5">Change given</td><td></td><td class="py-1.5 text-right tabular-nums">−{{ money(summary.change_given) }}</td>
                        </tr>
                        <tr v-for="r in summary.refunds" :key="r.method" class="border-t border-gray-100 text-gray-500">
                            <td class="py-1.5">{{ refundLabels[r.method] ?? r.method }} (returns)</td><td class="py-1.5 text-right">{{ r.count }}</td><td class="py-1.5 text-right tabular-nums">{{ money(r.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <Card title="Cash drawer">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt>Opening float</dt><dd class="tabular-nums">{{ money(summary.opening_cash) }}</dd></div>
                    <div class="flex justify-between"><dt>+ Cash sales</dt><dd class="tabular-nums">{{ money(summary.cash_sales) }}</dd></div>
                    <div class="flex justify-between"><dt>− Change given</dt><dd class="tabular-nums">{{ money(summary.change_given) }}</dd></div>
                    <div class="flex justify-between"><dt>− Cash refunds</dt><dd class="tabular-nums">{{ money(summary.cash_refunds) }}</dd></div>
                    <div class="flex justify-between"><dt>+ Cash in</dt><dd class="tabular-nums">{{ money(summary.cash_in) }}</dd></div>
                    <div class="flex justify-between"><dt>− Cash out</dt><dd class="tabular-nums">{{ money(summary.cash_out) }}</dd></div>
                    <div v-if="Number(summary.payments_in)" class="flex justify-between"><dt>+ Customer payments</dt><dd class="tabular-nums">{{ money(summary.payments_in) }}</dd></div>
                    <div v-if="Number(summary.payments_out)" class="flex justify-between"><dt>− Supplier payments</dt><dd class="tabular-nums">{{ money(summary.payments_out) }}</dd></div>
                    <div v-if="Number(summary.expenses)" class="flex justify-between"><dt>− Expenses paid</dt><dd class="tabular-nums">{{ money(summary.expenses) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>Expected</dt><dd class="tabular-nums">{{ money(summary.expected_cash) }}</dd></div>
                    <template v-if="session.status === 'closed'">
                        <div class="flex justify-between"><dt>Counted</dt><dd class="tabular-nums">{{ money(session.counted_cash) }}</dd></div>
                        <div class="flex justify-between font-semibold" :class="Number(session.difference) < 0 ? 'text-red-600' : 'text-green-700'">
                            <dt>Difference</dt><dd class="tabular-nums">{{ money(session.difference) }}</dd>
                        </div>
                        <p class="pt-2 text-xs text-gray-500">Closed {{ dateTime(session.closed_at) }} by {{ session.closed_by }}<span v-if="session.closing_note"> · {{ session.closing_note }}</span></p>
                    </template>
                </dl>

                <form v-if="canClose" class="no-print mt-5 space-y-3 border-t border-gray-100 pt-4" @submit.prevent="close">
                    <FormField label="Cash counted in drawer (₹)" :error="form.errors.counted_cash" required>
                        <TextInput v-model="form.counted_cash" type="number" min="0" step="0.01" />
                    </FormField>
                    <p v-if="diff !== null" class="text-sm font-medium" :class="diff < 0 ? 'text-red-600' : diff > 0 ? 'text-amber-700' : 'text-green-700'">
                        {{ diff === 0 ? 'Matches exactly.' : diff < 0 ? `Short by ${money(-diff)}` : `Excess ${money(diff)}` }}
                    </p>
                    <FormField label="Note" :error="form.errors.closing_note"><TextInput v-model="form.closing_note" placeholder="Optional" /></FormField>
                    <PrimaryButton class="w-full" :disabled="form.processing || form.counted_cash === ''">Close register</PrimaryButton>
                </form>
            </Card>
        </div>

        <Card v-if="session.movements.length" class="mt-6" title="Cash in / out" :padded="false">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="m in session.movements" :key="m.id">
                        <td class="px-4 py-2 text-xs text-gray-500">{{ dateTime(m.created_at) }}</td>
                        <td class="px-4 py-2">{{ m.reason }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ m.user }}</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums" :class="m.type === 'out' ? 'text-red-600' : 'text-green-700'">{{ m.type === 'out' ? '−' : '+' }}{{ money(m.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
