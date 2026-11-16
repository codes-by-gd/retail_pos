<script setup>
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    groups: { type: Object, required: true }, // { Module: { 'perm.name': 'Label' } }
    role: { type: Object, default: null },
});

const form = useForm({
    name: props.role?.name ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

const has = (p) => form.permissions.includes(p);
const toggle = (p) => (form.permissions = has(p) ? form.permissions.filter((x) => x !== p) : [...form.permissions, p]);

const groupKeys = (group) => Object.keys(props.groups[group]);
const groupState = (group) => {
    const n = groupKeys(group).filter(has).length;
    return n === 0 ? 'none' : n === groupKeys(group).length ? 'all' : 'some';
};
const toggleGroup = (group) => {
    const keys = groupKeys(group);
    form.permissions =
        groupState(group) === 'all'
            ? form.permissions.filter((p) => !keys.includes(p))
            : [...new Set([...form.permissions, ...keys])];
};

const submit = () => (props.role ? form.put(route('roles.update', props.role.id)) : form.post(route('roles.store')));
</script>

<template>
    <AppLayout :title="role ? 'Edit role' : 'New role'">
        <PageHeader :title="role ? `Edit role: ${role.name}` : 'New role'" />

        <form class="max-w-4xl space-y-6" @submit.prevent="submit">
            <Card>
                <FormField label="Role name" :error="form.errors.name" required class="max-w-sm">
                    <TextInput v-model="form.name" autofocus />
                </FormField>
            </Card>

            <Card title="Permissions" :description="`${form.permissions.length} selected`">
                <InputError :message="form.errors.permissions" class="mb-3" />
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div v-for="(perms, group) in groups" :key="group" class="rounded-lg border border-gray-200">
                        <label class="flex cursor-pointer items-center gap-3 border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                            <input
                                type="checkbox"
                                class="rounded-sm border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                :checked="groupState(group) === 'all'"
                                :indeterminate="groupState(group) === 'some'"
                                @change="toggleGroup(group)"
                            />
                            <span class="text-sm font-semibold text-gray-800">{{ group }}</span>
                        </label>
                        <div class="space-y-2 px-4 py-3">
                            <label v-for="(label, key) in perms" :key="key" class="flex cursor-pointer items-center gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    class="rounded-sm border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    :checked="has(key)"
                                    @change="toggle(key)"
                                />
                                <span class="text-gray-700">{{ label }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </Card>

            <div class="flex justify-end gap-3">
                <Link :href="route('roles.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing">{{ role ? 'Save changes' : 'Create role' }}</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
