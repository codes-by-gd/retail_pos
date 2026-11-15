<script setup>
import { useListFilters } from '@/composables/useListFilters';
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money } from '@/utils/format';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, CakeIcon, PlusIcon, UserGroupIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';

const props = defineProps({
    kind: { type: String, required: true }, // customer | supplier
    contacts: { type: Object, required: true },
    filters: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const isCustomer = computed(() => props.kind === 'customer');
const plural = computed(() => `${props.kind}s`);

const filters = useListFilters(`${props.kind}s.index`, {
    search: props.filters.search ?? '',
    group_id: props.filters.group_id ? Number(props.filters.group_id) : null,
    status: props.filters.status ?? null,
    balance: props.filters.balance ?? null,
    occasion: props.filters.occasion ?? null,
});

const balanceOptions = computed(() =>
    isCustomer.value
        ? [{ value: 'receivable', label: 'Owes us (due)' }, { value: 'payable', label: 'We owe them' }]
        : [{ value: 'payable', label: 'We owe them (payable)' }, { value: 'receivable', label: 'They owe us' }],
);

// "12 Mar" from an ISO date, for birthday badges.
const dayMonth = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '');

const columns = computed(() => [
    { key: 'name', label: isCustomer.value ? 'Customer' : 'Supplier' },
    { key: 'phone', label: 'Phone' },
    ...(isCustomer.value ? [{ key: 'group', label: 'Group' }] : [{ key: 'gstin', label: 'GSTIN' }]),
    { key: 'city', label: 'City' },
    { key: 'balance', label: 'Balance', align: 'right' },
    { key: 'is_active', label: 'Status' },
]);
</script>

<template>
    <AppLayout :title="isCustomer ? 'Customers' : 'Suppliers'">
        <PageHeader
            :title="isCustomer ? 'Customers' : 'Suppliers'"
            :description="isCustomer ? 'People you sell to. Phone number is used to find them at the counter.' : 'Mills, manufacturers and distributors you buy from.'"
        >
            <template #actions>
                <ButtonLink v-if="isCustomer && can('customers.manage')" :href="route('customer-groups.index')" variant="secondary">
                    <UserGroupIcon class="h-4 w-4" /> Groups
                </ButtonLink>
                <a
                    :href="route(`${plural}.export`)"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                >
                    <ArrowDownTrayIcon class="h-4 w-4" /> Export
                </a>
                <ButtonLink v-if="can(`${plural}.manage`)" :href="route(`${plural}.import`)" variant="secondary">
                    <ArrowUpTrayIcon class="h-4 w-4" /> Import
                </ButtonLink>
                <ButtonLink v-if="can(`${plural}.manage`)" :href="route(`${plural}.create`)">
                    <PlusIcon class="h-4 w-4" /> Add {{ kind }}
                </ButtonLink>
            </template>
        </PageHeader>

        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 md:grid-cols-4">
                <SearchInput v-model="filters.search" placeholder="Name, phone, code or GSTIN…" />
                <SelectInput v-if="isCustomer" v-model="filters.group_id" :options="groups" placeholder="All groups" />
                <SelectInput v-model="filters.balance" :options="balanceOptions" placeholder="Any balance" />
                <SelectInput
                    v-if="isCustomer"
                    v-model="filters.occasion"
                    :options="[
                        { value: 'birthday', label: 'Birthday in next 30 days' },
                        { value: 'anniversary', label: 'Anniversary in next 30 days' },
                    ]"
                    placeholder="Any occasion"
                />
                <SelectInput
                    v-else
                    v-model="filters.status"
                    :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]"
                    placeholder="Any status"
                />
            </div>

            <DataTable :columns="columns" :rows="contacts.data" :empty="`No ${plural} found.`">
                <template #name="{ row }">
                    <Link :href="route(`${plural}.show`, row.id)" class="font-medium text-gray-900 hover:text-indigo-700">{{ row.name }}</Link>
                    <Badge v-if="row.is_walk_in" color="indigo" class="ml-2">Built-in</Badge>
                    <Badge v-if="row.type === 'both'" color="amber" class="ml-2">Customer & supplier</Badge>
                    <p class="text-xs text-gray-500">
                        <span class="font-mono">{{ row.code }}</span><span v-if="row.company_name"> · {{ row.company_name }}</span>
                    </p>
                    <p v-if="filters.occasion" class="mt-0.5 flex items-center gap-1 text-xs text-pink-700">
                        <CakeIcon class="h-3.5 w-3.5" />
                        {{ filters.occasion === 'anniversary' ? `Anniversary ${dayMonth(row.anniversary)}` : `Birthday ${dayMonth(row.date_of_birth)}` }}
                    </p>
                </template>
                <template #phone="{ row }"><span class="tabular-nums">{{ row.phone ?? '—' }}</span></template>
                <template #gstin="{ row }"><span class="font-mono text-xs">{{ row.gstin ?? '—' }}</span></template>
                <template #balance="{ row }">
                    <span v-if="row.balance > 0" class="font-medium text-amber-700" title="They owe us">{{ money(row.balance) }} Dr</span>
                    <span v-else-if="row.balance < 0" class="font-medium text-indigo-700" title="We owe them">{{ money(-row.balance) }} Cr</span>
                    <span v-else class="text-gray-400">—</span>
                </template>
                <template #is_active="{ row }">
                    <Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
                </template>
            </DataTable>
            <Pagination :paginator="contacts" />
        </Card>
        <p class="mt-3 text-xs text-gray-500">Dr = they owe us · Cr = we owe them.</p>
    </AppLayout>
</template>
