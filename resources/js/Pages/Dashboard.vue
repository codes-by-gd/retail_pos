<script setup>
import Card from '@/Components/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money } from '@/utils/format';
import { BanknotesIcon, CubeIcon, ReceiptPercentIcon, ShoppingBagIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ lowStock: { type: Number, default: 0 } });

// Sales KPIs are placeholders until Phase 5 (Dashboard & Reports); stock is live.
const kpis = [
    { label: "Today's sales", value: money(0), icon: BanknotesIcon },
    { label: 'Bills today', value: '0', icon: ReceiptPercentIcon },
    { label: 'Average bill value', value: money(0), icon: ShoppingBagIcon },
    { label: 'Low / out of stock items', value: String(props.lowStock), icon: CubeIcon, href: route('stock.index', { level: 'low' }) },
];

const roadmap = [
    { phase: 'Phase 0', title: 'Foundation — users, roles, branches, settings', done: true },
    { phase: 'Phase 1', title: 'Catalog & inventory, barcodes, labels', done: true },
    { phase: 'Phase 2', title: 'Customers & suppliers', done: true },
    { phase: 'Phase 3', title: 'POS billing panel' },
    { phase: 'Phase 4', title: 'Sales, purchases & quotations' },
    { phase: 'Phase 5', title: 'Dashboard & reports (incl. GST)' },
    { phase: 'Phase 6', title: 'Notifications, expenses, audit log' },
];
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-gray-900">Welcome back, {{ $page.props.auth.user.name }}</h2>
            <p class="text-sm text-gray-500">
                Working in <span class="font-medium text-gray-700">{{ $page.props.branch?.current?.name ?? 'no branch' }}</span>
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <component
                :is="kpi.href ? Link : 'div'"
                v-for="kpi in kpis"
                :key="kpi.label"
                :href="kpi.href"
                class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                :class="kpi.href ? 'transition hover:border-indigo-300' : ''"
            >
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-500">{{ kpi.label }}</p>
                    <component :is="kpi.icon" class="h-5 w-5 text-indigo-500" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900">{{ kpi.value }}</p>
            </component>
        </div>

        <Card class="mt-6" title="Build roadmap" description="Modules light up in the sidebar as each phase is delivered.">
            <ol class="space-y-2">
                <li v-for="r in roadmap" :key="r.phase" class="flex items-center gap-3 text-sm">
                    <span
                        class="w-16 shrink-0 rounded px-2 py-0.5 text-center text-xs font-medium"
                        :class="r.done ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'"
                    >{{ r.phase }}</span>
                    <span :class="r.done ? 'text-gray-900' : 'text-gray-500'">{{ r.title }}</span>
                    <span v-if="r.done" class="text-xs text-green-600">✓ done</span>
                </li>
            </ol>
        </Card>
    </AppLayout>
</template>
