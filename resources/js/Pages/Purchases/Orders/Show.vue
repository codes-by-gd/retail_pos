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
import { PencilSquareIcon, PrinterIcon, TruckIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ order: { type: Object, required: true }, statuses: { type: Object, required: true } });
const { can } = usePermissions();
const cancelling = ref(false);
const color = { draft: 'gray', ordered: 'indigo', partially_received: 'amber', received: 'green', cancelled: 'red' };
const setStatus = (status) => router.post(route('purchase-orders.status', props.order.id), { status }, { onFinish: () => (cancelling.value = false) });
</script>

<template>
    <AppLayout :title="order.reference_no">
        <PageHeader :title="`Purchase order ${order.reference_no}`" :description="`${order.supplier.name} · ordered ${date(order.order_date)} by ${order.user}`">
            <template #actions>
                <button type="button" class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50"
                    @click="printUrl(route('purchase-orders.print', order.id))"><PrinterIcon class="h-4 w-4" /> Print</button>
                <template v-if="can('purchases.manage')">
                    <ButtonLink v-if="order.status === 'draft'" :href="route('purchase-orders.edit', order.id)" variant="secondary"><PencilSquareIcon class="h-4 w-4" /> Edit</ButtonLink>
                    <PrimaryButton v-if="order.status === 'draft'" type="button" @click="setStatus('ordered')">Place order</PrimaryButton>
                    <ButtonLink v-if="['ordered', 'partially_received'].includes(order.status)" :href="route('purchases.create', { order: order.id })"><TruckIcon class="h-4 w-4" /> Receive goods</ButtonLink>
                </template>
            </template>
        </PageHeader>
        <div class="mb-6 flex items-center gap-3 text-sm">
            <Badge :color="color[order.status]">{{ statuses[order.status] }}</Badge>
            <span v-if="order.expected_date" class="text-gray-500">Expected {{ date(order.expected_date) }}</span>
            <button v-if="can('purchases.manage') && ['draft', 'ordered', 'partially_received'].includes(order.status)" type="button" class="text-red-600 hover:underline" @click="cancelling = true">Cancel order</button>
        </div>
        <Card title="Items" :padded="false">
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-500">
                    <tr><th class="px-4 py-2 text-left">Item</th><th class="px-4 py-2 text-right">Ordered</th><th class="px-4 py-2 text-right">Received</th><th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-right">Amount</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="i in order.items" :key="i.id">
                        <td class="px-4 py-2">{{ i.name }}<p class="text-xs text-gray-500">{{ i.sku }}</p></td>
                        <td class="px-4 py-2 text-right">{{ qty(i.quantity) }} {{ i.unit }}</td>
                        <td class="px-4 py-2 text-right" :class="i.received >= i.quantity ? 'text-green-700' : i.received > 0 ? 'text-amber-700' : 'text-gray-400'">{{ qty(i.received) }}</td>
                        <td class="px-4 py-2 text-right">{{ money(i.unit_cost) }}</td>
                        <td class="px-4 py-2 text-right">{{ money(i.quantity * i.unit_cost) }}</td>
                    </tr>
                </tbody>
                <tfoot><tr class="font-semibold"><td colspan="4" class="px-4 py-2 text-right">Estimated value (excl. GST)</td><td class="px-4 py-2 text-right">{{ money(order.estimated_total) }}</td></tr></tfoot>
            </table></div>
        </Card>
        <Card v-if="order.purchases.length" class="mt-6" title="Deliveries received" :padded="false">
            <table class="w-full text-sm"><tbody class="divide-y divide-gray-100">
                <tr v-for="p in order.purchases" :key="p.id">
                    <td class="px-4 py-2"><Link :href="route('purchases.show', p.id)" class="font-mono text-indigo-600 hover:text-indigo-800">{{ p.reference_no }}</Link></td>
                    <td class="px-4 py-2 text-gray-500">Bill {{ p.bill ?? '—' }} · {{ date(p.date) }}</td>
                    <td class="px-4 py-2 text-right font-medium">{{ money(p.total) }}</td>
                </tr>
            </tbody></table>
        </Card>
        <p v-if="order.notes" class="mt-4 whitespace-pre-line text-sm text-gray-600">{{ order.notes }}</p>
        <ConfirmModal :show="cancelling" title="Cancel this order?" message="Goods already received stay in stock." confirm-text="Cancel order" @confirm="setStatus('cancelled')" @close="cancelling = false" />
    </AppLayout>
</template>
