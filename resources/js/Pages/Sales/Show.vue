<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { printUrl } from '@/Pos/helpers';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money, qty } from '@/utils/format';
import { PrinterIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';

defineProps({ sale: { type: Object, required: true } });
const refundLabels = { cash: 'Cash refund', card: 'Card refund', upi: 'UPI refund', account: 'Store credit', exchange: 'Exchange credit' };
</script>

<template>
    <AppLayout :title="sale.invoice_no">
        <PageHeader :title="`Invoice ${sale.invoice_no}`" :description="`${dateTime(sale.sold_at)} · ${sale.branch} · cashier ${sale.cashier}`">
            <template #actions>
                <button v-for="f in [{ v: '80mm', l: 'Receipt' }, { v: 'a4', l: 'A4 invoice' }]" :key="f.v" type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    @click="printUrl(sale.print_url, f.v)">
                    <PrinterIcon class="h-4 w-4" /> {{ f.l }}
                </button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Items" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2 text-left">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2 text-right">Rate</th>
                                <th class="px-4 py-2 text-right">Disc.</th><th class="px-4 py-2 text-right">Taxable</th><th class="px-4 py-2 text-right">GST</th><th class="px-4 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="i in sale.items" :key="i.id">
                                <td class="px-4 py-2">
                                    {{ i.name }}<span v-if="i.variant" class="text-gray-500"> · {{ i.variant }}</span>
                                    <p class="text-xs text-gray-500">{{ i.sku }}<span v-if="i.hsn"> · HSN {{ i.hsn }}</span></p>
                                    <p v-if="i.returned" class="text-xs text-amber-700">{{ qty(i.returned) }} returned</p>
                                </td>
                                <td class="px-4 py-2 text-right">{{ qty(i.quantity) }} {{ i.unit }}</td>
                                <td class="px-4 py-2 text-right">{{ money(i.unit_price) }}<p v-if="i.unit_price !== i.list_price" class="text-xs text-gray-400 line-through">{{ money(i.list_price) }}</p></td>
                                <td class="px-4 py-2 text-right">{{ i.discount ? money(i.discount) : '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ money(i.taxable) }}</td>
                                <td class="px-4 py-2 text-right">{{ money(i.tax) }}<p class="text-xs text-gray-500">{{ i.gst_rate }}%</p></td>
                                <td class="px-4 py-2 text-right font-medium">{{ money(i.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <div class="space-y-6">
                <Card title="Customer">
                    <p class="font-medium">
                        <Link v-if="sale.customer" :href="route('customers.show', sale.customer.id)" class="text-indigo-600 hover:text-indigo-800">{{ sale.customer_name }}</Link>
                        <span v-else>{{ sale.customer_name }}</span>
                    </p>
                    <p class="text-sm text-gray-500">{{ sale.customer_phone }}</p>
                    <p v-if="sale.customer_gstin" class="font-mono text-sm">GSTIN {{ sale.customer_gstin }}</p>
                    <p class="mt-2 text-xs text-gray-500">{{ sale.is_inter_state ? 'Inter-state supply (IGST)' : 'Intra-state supply (CGST + SGST)' }} · {{ sale.price_type }} prices</p>
                </Card>
                <Card title="Totals">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ money(sale.subtotal) }}</dd></div>
                        <div v-if="sale.line_discount_total" class="flex justify-between text-green-700"><dt>Item discounts</dt><dd>−{{ money(sale.line_discount_total) }}</dd></div>
                        <div v-if="sale.bill_discount_total" class="flex justify-between text-green-700"><dt>Bill discount</dt><dd>−{{ money(sale.bill_discount_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>Taxable</dt><dd>{{ money(sale.taxable_total) }}</dd></div>
                        <template v-if="sale.is_inter_state"><div class="flex justify-between text-gray-500"><dt>IGST</dt><dd>{{ money(sale.igst_total) }}</dd></div></template>
                        <template v-else>
                            <div class="flex justify-between text-gray-500"><dt>CGST</dt><dd>{{ money(sale.cgst_total) }}</dd></div>
                            <div class="flex justify-between text-gray-500"><dt>SGST</dt><dd>{{ money(sale.sgst_total) }}</dd></div>
                        </template>
                        <div v-if="sale.round_off" class="flex justify-between text-gray-500"><dt>Round off</dt><dd>{{ money(sale.round_off) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-200 pt-1 text-base font-semibold"><dt>Total</dt><dd>{{ money(sale.grand_total) }}</dd></div>
                    </dl>
                    <div class="mt-4 space-y-1 border-t border-gray-100 pt-3 text-sm">
                        <div v-for="(p, n) in sale.payments" :key="n" class="flex justify-between"><dt>{{ p.method }}<span v-if="p.reference" class="text-xs text-gray-500"> · {{ p.reference }}</span></dt><dd>{{ money(p.amount) }}</dd></div>
                        <div v-if="sale.change_given" class="flex justify-between text-gray-500"><dt>Change</dt><dd>−{{ money(sale.change_given) }}</dd></div>
                        <div v-if="sale.account_amount" class="flex justify-between font-medium text-amber-700"><dt>On account</dt><dd>{{ money(sale.account_amount) }}</dd></div>
                    </div>
                    <div class="mt-3"><Badge :color="{ paid: 'green', partial: 'amber', due: 'red' }[sale.payment_status]">{{ sale.payment_status }}</Badge></div>
                </Card>
            </div>
        </div>

        <Card v-if="sale.returns.length" class="mt-6" title="Returns / credit notes" :padded="false">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="r in sale.returns" :key="r.id">
                        <td class="px-4 py-2 font-mono">{{ r.credit_note_no }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ dateTime(r.returned_at) }} · {{ r.user }}<span v-if="r.reason"> · {{ r.reason }}</span></td>
                        <td class="px-4 py-2">{{ refundLabels[r.refund_method] }}</td>
                        <td class="px-4 py-2 text-right font-medium">{{ money(r.total) }}</td>
                        <td class="px-4 py-2 text-right"><button type="button" class="text-indigo-600 hover:text-indigo-800" @click="printUrl(r.print_url)">Print</button></td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
