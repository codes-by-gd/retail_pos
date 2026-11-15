<script setup>
import Badge from '@/Components/Badge.vue';
import BarChart from '@/Components/BarChart.vue';
import BarList from '@/Components/BarList.vue';
import Card from '@/Components/Card.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, money, number, qty } from '@/utils/format';
import { ArrowTrendingDownIcon, ArrowTrendingUpIcon } from '@heroicons/vue/20/solid';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    period: { type: String, required: true },
    periods: { type: Array, required: true },
    allBranches: { type: Boolean, default: false },
    canAllBranches: { type: Boolean, default: false },
    branchLabel: { type: String, default: '' },
    data: { type: Object, default: null },
});

const { can } = usePermissions();
const canReports = computed(() => can('reports.sales') || can('reports.inventory') || can('reports.financial'));

const go = (changes) =>
    router.get(route('dashboard'), { period: props.period, branch: props.allBranches ? 'all' : undefined, ...changes }, { preserveScroll: true, replace: true });

const k = computed(() => props.data?.kpis ?? {});
const kpis = computed(() => {
    if (!props.data) return [];
    const list = [
        { label: 'Net sales', value: money(k.value.net_sales.value), change: k.value.net_sales.change,
            hint: k.value.returns.value ? `after ${money(k.value.returns.value)} returns` : 'bills incl. GST' },
        { label: 'Bills', value: number(k.value.bills.value), change: k.value.bills.change },
        { label: 'Average bill', value: money(k.value.average.value), change: k.value.average.change },
        { label: 'Pieces sold', value: qty(k.value.pieces.value), change: k.value.pieces.change, hint: 'net of returns' },
    ];
    if (k.value.profit) {
        list.push({ label: 'Gross profit', value: money(k.value.profit.value), change: k.value.profit.change,
            hint: [k.value.profit.margin !== null ? `${number(k.value.profit.margin, 1)}% margin` : '',
                k.value.profit.expenses ? `net ${money(k.value.profit.value - k.value.profit.expenses)} after expenses` : ''].filter(Boolean).join(' · ') });
    }
    return list;
});

const trend = computed(() => props.data?.trend ?? []);
const hourly = computed(() => ['today', 'yesterday'].includes(props.period));
const trendHasData = computed(() => trend.value.some((t) => t.value !== 0));
const statusColor = { paid: 'green', partial: 'amber', due: 'red' };
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">Welcome back, {{ $page.props.auth.user.name }}</h2>
                <p class="text-sm text-gray-500">
                    Showing <span class="font-medium text-gray-700">{{ branchLabel }}</span>
                    <template v-if="canReports"> · <Link :href="route('reports.index')" class="text-indigo-600 hover:text-indigo-800">All reports →</Link></template>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap rounded-md border border-gray-200 bg-white p-0.5 text-sm shadow-sm" role="group" aria-label="Period">
                    <button
                        v-for="p in periods"
                        :key="p.value"
                        type="button"
                        class="rounded px-2.5 py-1"
                        :class="p.value === period ? 'bg-indigo-600 font-medium text-white' : 'text-gray-600 hover:bg-gray-100'"
                        :aria-pressed="p.value === period"
                        @click="go({ period: p.value })"
                    >{{ p.label }}</button>
                </div>
                <div v-if="canAllBranches" class="flex rounded-md border border-gray-200 bg-white p-0.5 text-sm shadow-sm" role="group" aria-label="Branches">
                    <button type="button" class="rounded px-2.5 py-1" :class="!allBranches ? 'bg-gray-800 font-medium text-white' : 'text-gray-600 hover:bg-gray-100'"
                        @click="go({ branch: undefined })">This branch</button>
                    <button type="button" class="rounded px-2.5 py-1" :class="allBranches ? 'bg-gray-800 font-medium text-white' : 'text-gray-600 hover:bg-gray-100'"
                        @click="go({ branch: 'all' })">All branches</button>
                </div>
            </div>
        </div>

        <p v-if="!data" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">You are not assigned to any branch yet. Ask an administrator to add you to one.</p>

        <template v-else>
            <!-- KPIs -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" :class="kpis.length > 4 ? 'xl:grid-cols-5' : 'xl:grid-cols-4'">
                <div v-for="kpi in kpis" :key="kpi.label" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-500">{{ kpi.label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ kpi.value }}</p>
                    <p v-if="kpi.change !== null && kpi.change !== undefined" class="mt-1 flex items-center gap-1 text-xs" :title="`Compared with ${data.previousLabel}`">
                        <span class="inline-flex items-center gap-0.5 font-medium" :class="kpi.change >= 0 ? 'text-green-700' : 'text-red-700'">
                            <component :is="kpi.change >= 0 ? ArrowTrendingUpIcon : ArrowTrendingDownIcon" class="h-4 w-4" aria-hidden="true" />
                            {{ kpi.change >= 0 ? '+' : '' }}{{ number(kpi.change, 1) }}%
                        </span>
                        <span class="truncate text-gray-500">vs {{ data.previousLabel }}</span>
                    </p>
                    <p v-else class="mt-1 truncate text-xs text-gray-400">nothing to compare with</p>
                    <p v-if="kpi.hint" class="mt-0.5 truncate text-xs text-gray-500">{{ kpi.hint }}</p>
                </div>
            </div>

            <!-- Trend + payment modes -->
            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <Card class="lg:col-span-2" :title="hourly ? 'Net sales by hour' : 'Net sales by day'" :description="`${data.kpis.bills.value} bills · returns subtracted on the day they happened`">
                    <BarChart v-if="trendHasData" :labels="trend.map((t) => t.label)" :values="trend.map((t) => t.value)" label="Net sales"
                        :titles="trend.map((t) => t.title ?? t.label)" :details="trend.map((t) => `${t.bills} bill${t.bills === 1 ? '' : 's'}`)" :height="250" />
                    <p v-else class="py-20 text-center text-sm text-gray-500">No sales in this period yet.</p>
                </Card>
                <Card title="Payment modes" description="Money collected at billing (cash after change).">
                    <BarList :items="data.paymentModes.map((m) => ({ label: m.label, value: m.value, sub: `${number(m.share, 1)}%` }))" empty="No payments yet." />
                </Card>
            </div>

            <!-- Top sellers -->
            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <Card title="Top articles" description="By net sales">
                    <BarList :items="data.topProducts.map((p) => ({ label: p.label, value: p.value, hint: `· ${qty(p.qty)} pcs` }))" empty="No sales yet." />
                </Card>
                <Card title="Top categories" description="By net sales">
                    <BarList :items="data.topCategories.map((c) => ({ label: c.label, value: c.value, hint: `· ${qty(c.qty)}` }))" empty="No sales yet." />
                </Card>
                <div class="space-y-5">
                    <Card v-if="data.drawers" title="Cash in drawers" description="Expected cash in open registers right now">
                        <ul v-if="data.drawers.length" class="space-y-2 text-sm">
                            <li v-for="d in data.drawers" :key="d.id" class="flex items-center justify-between gap-2">
                                <Link :href="route('register-sessions.show', d.id)" class="min-w-0 truncate text-indigo-600 hover:text-indigo-800">{{ allBranches ? `${d.branch} · ` : '' }}{{ d.register }} · {{ d.opened_by }}</Link>
                                <span class="font-medium tabular-nums">{{ money(d.expected) }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-gray-500">No register is open.</p>
                    </Card>
                    <Card v-if="data.receivables || data.payables" title="Money outstanding">
                        <dl class="space-y-2 text-sm">
                            <div v-if="data.receivables" class="flex justify-between gap-2">
                                <dt class="text-gray-600">Customers owe us <span class="text-xs text-gray-400">({{ data.receivables.count }})</span></dt>
                                <dd class="font-medium tabular-nums text-gray-900">{{ money(data.receivables.total) }}</dd>
                            </div>
                            <div v-if="data.payables" class="flex justify-between gap-2">
                                <dt class="text-gray-600">We owe suppliers <span class="text-xs text-gray-400">({{ data.payables.count }})</span></dt>
                                <dd class="font-medium tabular-nums text-gray-900">{{ money(data.payables.total) }}</dd>
                            </div>
                        </dl>
                        <ul v-if="data.receivables?.top.length" class="mt-3 space-y-1 border-t border-gray-100 pt-3 text-sm">
                            <li v-for="c in data.receivables.top" :key="c.id" class="flex justify-between gap-2">
                                <Link :href="route(c.type === 'supplier' ? 'suppliers.show' : 'customers.show', c.id)" class="min-w-0 truncate text-indigo-600 hover:text-indigo-800">{{ c.name }}</Link>
                                <span class="tabular-nums text-gray-700">{{ money(c.balance) }}</span>
                            </li>
                        </ul>
                        <Link v-if="can('reports.financial')" :href="route('reports.show', 'receivables')" class="mt-3 block text-xs text-indigo-600 hover:text-indigo-800">Ageing report →</Link>
                    </Card>
                </div>
            </div>

            <!-- Recent sales + low stock -->
            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                <Card v-if="data.recentSales" title="Recent bills" :padded="false">
                    <ul class="divide-y divide-gray-100 text-sm">
                        <li v-for="s in data.recentSales" :key="s.id" class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <div class="min-w-0">
                                <Link :href="route('sales.show', s.id)" class="font-mono text-xs font-medium text-indigo-600 hover:text-indigo-800">{{ s.invoice_no }}</Link>
                                <p class="truncate text-xs text-gray-500">{{ s.customer_name }} · {{ dateTime(s.sold_at) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="font-medium tabular-nums" :class="s.status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-900'">{{ money(s.grand_total) }}</span>
                                <Badge v-if="s.status === 'cancelled'" color="gray">Cancelled</Badge>
                                <Badge v-else-if="s.payment_status !== 'paid'" :color="statusColor[s.payment_status]">{{ s.payment_status === 'partial' ? 'Part paid' : 'Due' }}</Badge>
                            </div>
                        </li>
                        <li v-if="!data.recentSales.length" class="px-5 py-8 text-center text-gray-500">No bills yet.</li>
                    </ul>
                </Card>
                <Card v-if="data.lowStock" title="Low stock" :description="`${data.lowStock.count} item${data.lowStock.count === 1 ? '' : 's'} at or below the reorder level`" :padded="false">
                    <ul class="divide-y divide-gray-100 text-sm">
                        <li v-for="(i, idx) in data.lowStock.items" :key="idx" class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-gray-900">{{ i.name }}</p>
                                <p class="text-xs text-gray-500">{{ i.sku }}<span v-if="i.branch"> · {{ i.branch }}</span></p>
                            </div>
                            <span class="shrink-0 text-xs tabular-nums" :class="i.qty <= 0 ? 'font-medium text-red-700' : 'text-amber-700'">
                                {{ i.qty <= 0 ? 'Out of stock' : `${qty(i.qty)} left` }} <span class="text-gray-400">/ {{ qty(i.reorder) }}</span>
                            </span>
                        </li>
                        <li v-if="!data.lowStock.items.length" class="px-5 py-8 text-center text-gray-500">All stocked up.</li>
                    </ul>
                    <div v-if="data.lowStock.count" class="flex gap-4 border-t border-gray-100 px-5 py-3 text-xs">
                        <Link :href="route('stock.index', { level: 'low' })" class="text-indigo-600 hover:text-indigo-800">Stock page →</Link>
                        <Link v-if="can('reports.inventory')" :href="route('reports.show', 'low-stock')" class="text-indigo-600 hover:text-indigo-800">Reorder list →</Link>
                    </div>
                </Card>
            </div>
        </template>
    </AppLayout>
</template>
