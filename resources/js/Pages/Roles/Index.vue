<script setup>
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { LockClosedIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ roles: { type: Array, required: true } });

const deleting = ref(null);
const columns = [
    { key: 'name', label: 'Role' },
    { key: 'permissions_count', label: 'Permissions', align: 'right' },
    { key: 'users_count', label: 'Users', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];

const destroy = () =>
    router.delete(route('roles.destroy', deleting.value.id), { preserveScroll: true, onFinish: () => (deleting.value = null) });
</script>

<template>
    <AppLayout title="Roles & Permissions">
        <PageHeader title="Roles & permissions" description="Group permissions into roles, then give each user a role.">
            <template #actions>
                <Link
                    :href="route('roles.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500"
                >
                    <PlusIcon class="h-4 w-4" /> Add role
                </Link>
            </template>
        </PageHeader>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="roles">
                <template #name="{ row }">
                    <span class="font-medium text-gray-900">{{ row.name }}</span>
                    <Badge v-if="row.locked" color="amber" class="ml-2"><LockClosedIcon class="mr-1 h-3 w-3" />Full access</Badge>
                </template>
                <template #actions="{ row }">
                    <div v-if="!row.locked" class="flex justify-end gap-3 text-sm font-medium">
                        <Link :href="route('roles.edit', row.id)" class="text-indigo-600 hover:text-indigo-800">Edit</Link>
                        <button class="text-red-600 hover:text-red-800" @click="deleting = row">Delete</button>
                    </div>
                </template>
            </DataTable>
        </Card>

        <ConfirmModal
            :show="!!deleting"
            title="Delete role?"
            :message="`The “${deleting?.name}” role will be removed.`"
            @confirm="destroy"
            @close="deleting = null"
        />
    </AppLayout>
</template>
