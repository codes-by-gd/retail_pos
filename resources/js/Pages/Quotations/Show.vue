<script setup>
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { printUrl } from '@/Pos/helpers';
import { date, money, qty } from '@/utils/format';
import { PencilSquareIcon, PrinterIcon, ShoppingCartIcon } from '@heroicons/vue/24/outline';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ quotation: { type: Object, required: true }, statuses: { type: Object, required: true } });
const { can } = usePermissions();
const deleting = ref(false);
const color = { draft: 'gray', sent: 'indigo', accepted: 'green', rejected: 'red', converted: 'amber' };

const setStatus = (status) => router.post(route('quotations.status', props.quotation.id), { status }, { preserveScroll: true });
const convert = () => router.post(route('quotations.convert', props.quotation.id));
const destroy = () => router.delete(route('quotations.destroy', props.quotation.id));
</script>

<template>
    <AppLayout :title="quotation.reference_no">
        <PageHeader :title="`Quotation ${quotation.reference_no}`" :description="`${date(quotation.date)} · by ${quotation.user}`">
            <template #actions>
                <button type="button" class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    @click="printUrl(route('quotations.print', quotation.id))"><PrinterIcon class="h-4 w-4" /> Print</button>
                <ButtonLink v-if="quotation.editable" :href="route('quotations.edit', quotation.id)" variant="secondary"><PencilSquareIcon class="h-4 w-4" /> Edit</ButtonLink>
                <PrimaryButton v-if="can('pos.access') && quotation.status !== 'converted' && quotation.status !== 'rejected'" type="button" @click="convert">
                    <ShoppingCartIcon class="h-4 w-4" /> Convert to bill
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="mb-6 flex flex-wrap items-center gap-2 text-sm">
            <Badge :color="color[quotation.status]">{{ statuses[quotation.status] }}</Badge>
            <Badge v-if="quotation.expired" color="red">Expired {{ date(quotation.valid_until) }}</Badge>
            <span v-else-if="quotation.valid_until" class="text-gray-500">Valid until {{ date(quotation.valid_until) }}</span>
            <template v-if="quotation.editable || quotation.status === 'accepted'">
                <span class="mx-2 text-gray-300">|</span>
                <button v-if="quotation.status === 'draft'" type="button" class="text-indigo-600 hover:underline" @click="setStatus('sent')">Mark sent</button>
                <button v-if="quotation.status !== 'accepted'" type="button" class="text-green-700 hover:underline" @click="setStatus('accepted')">Mark accepted</button>
                <button type="button" class="text-red-600 hover:underline" @click="setStatus('rejected')">Mark rejected</button>
                <button v-if="quotation.status !== 'converted'" type="button" class="text-gray-500 hover:text-red-600 hover:underline" @click="deleting = true">Delete</button>
            </template>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Items" :padded="false">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-500">
                        <tr><th class="px-4 py-2 text-left">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2 text-right">Rate</th>
                            <th class="px-4 py-2 text-right">Disc.</th><th class="px-4 py-2 text-right">GST</th><th class="px-4 py-2 text-right">Amount</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="i in quotation.items" :key="i.id">
                            <td class="px-4 py-2">{{ i.name }}<span v-if="i.variant" class="text-gray-500"> · {{ i.variant }}</span><p class="text-xs text-gray-500">{{ i.sku }}</p></td>
                            <td class="px-4 py-2 text-right">{{ qty(i.quantity) }} {{ i.unit }}</td>
                            <td class="px-4 py-2 text-right">{{ money(i.unit_price) }}</td>
                            <td class="px-4 py-2 text-right">{{ i.discount ? money(i.discount) : '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ i.gst_rate }}%</td>
                            <td class="px-4 py-2 text-right font-medium">{{ money(i.total) }}</td>
                        </tr>
                    </tbody>
                </table></div>
            </Card>
            <div class="space-y-6">
                <Card title="Customer">
                    <p class="font-medium">{{ quotation.customer_name }}</p>
                    <p class="text-sm text-gray-500">{{ quotation.customer_phone }}</p>
                    <p v-if="quotation.is_inter_state" class="mt-1 text-xs text-indigo-700">Other state — IGST</p>
                </Card>
                <Card title="Totals">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ money(quotation.subtotal) }}</dd></div>
                        <div v-if="quotation.line_discount_total + quotation.bill_discount_total" class="flex justify-between text-green-700"><dt>Discounts</dt>
                            <dd>−{{ money(quotation.line_discount_total + quotation.bill_discount_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>Taxable</dt><dd>{{ money(quotation.taxable_total) }}</dd></div>
                        <div class="flex justify-between text-gray-500"><dt>GST</dt><dd>{{ money(quotation.cgst_total + quotation.sgst_total + quotation.igst_total) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-200 pt-1 text-base font-semibold"><dt>Total</dt><dd>{{ money(quotation.grand_total) }}</dd></div>
                    </dl>
                    <p v-if="quotation.notes" class="mt-4 whitespace-pre-line border-t border-gray-100 pt-3 text-sm text-gray-600">{{ quotation.notes }}</p>
                </Card>
            </div>
        </div>
        <p v-if="quotation.status !== 'converted'" class="mt-4 text-xs text-gray-500">
            "Convert to bill" opens the POS with these items and discounts; prices and stock are checked again at that moment.
        </p>
        <ConfirmModal :show="deleting" title="Delete quotation?" :message="`${quotation.reference_no} will be removed.`" @confirm="destroy" @close="deleting = false" />
    </AppLayout>
</template>
