<script setup>
import { useListFilters } from '@/composables/useListFilters';
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    roles: { type: Array, required: true },
});

const { can } = usePermissions();

const filters = useListFilters('users.index', {
    search: props.filters.search ?? '',
    role: props.filters.role ?? null,
    status: props.filters.status ?? null,
});


const columns = [
    { key: 'name', label: 'User' },
    { key: 'phone', label: 'Phone' },
    { key: 'roles', label: 'Role' },
    { key: 'branches', label: 'Branches' },
    { key: 'last_login_at', label: 'Last login' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout title="Users">
        <PageHeader title="Users" description="Staff accounts, their role and the branches they can work in.">
            <template v-if="can('users.manage')" #actions>
                <Link
                    :href="route('users.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-xs hover:bg-indigo-500"
                >
                    <PlusIcon class="h-4 w-4" /> Add user
                </Link>
            </template>
        </PageHeader>

        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-3">
                <SearchInput v-model="filters.search" placeholder="Search name, email or phone…" />
                <SelectInput v-model="filters.role" :options="roles" placeholder="All roles" />
                <SelectInput
                    v-model="filters.status"
                    :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]"
                    placeholder="Any status"
                />
            </div>

            <DataTable :columns="columns" :rows="users.data">
                <template #name="{ row }">
                    <div class="font-medium text-gray-900">{{ row.name }}</div>
                    <div class="text-xs text-gray-500">{{ row.email }}</div>
                </template>
                <template #roles="{ row }">
                    <Badge v-for="r in row.roles" :key="r" color="indigo">{{ r }}</Badge>
                </template>
                <template #branches="{ row }">
                    <span class="font-mono text-xs">{{ row.branches.join(', ') || (row.roles.includes('Admin') ? 'All' : '—') }}</span>
                </template>
                <template #last_login_at="{ row }">
                    <span class="text-xs text-gray-500">{{ row.last_login_at ? dateTime(row.last_login_at) : 'Never' }}</span>
                </template>
                <template #is_active="{ row }">
                    <Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
                </template>
                <template #actions="{ row }">
                    <Link
                        v-if="can('users.manage') && (!row.roles.includes('Admin') || $page.props.auth.user.is_admin)"
                        :href="route('users.edit', row.id)"
                        class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        Edit
                    </Link>
                </template>
            </DataTable>

            <Pagination :paginator="users" />
        </Card>
    </AppLayout>
</template>
