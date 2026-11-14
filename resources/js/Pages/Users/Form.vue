<script setup>
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    user: { type: Object, default: null },
    roles: { type: Array, required: true },
    branches: { type: Array, required: true },
    canAssignAdmin: { type: Boolean, default: false },
});

const editing = computed(() => !!props.user);

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    password: '',
    password_confirmation: '',
    pin: '',
    is_active: props.user?.is_active ?? true,
    role: props.user?.role ?? null,
    branch_ids: props.user?.branch_ids ?? [],
});

const roleOptions = computed(() => props.roles.filter((r) => r !== 'Admin' || props.canAssignAdmin));
const isAdminRole = computed(() => form.role === 'Admin');

const toggleBranch = (id) => {
    form.branch_ids = form.branch_ids.includes(id) ? form.branch_ids.filter((b) => b !== id) : [...form.branch_ids, id];
};

const submit = () => {
    editing.value ? form.put(route('users.update', props.user.id)) : form.post(route('users.store'));
};
</script>

<template>
    <AppLayout :title="editing ? 'Edit user' : 'New user'">
        <PageHeader :title="editing ? `Edit ${user.name}` : 'New user'" />

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <Card title="Account">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Full name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" autofocus />
                    </FormField>
                    <FormField label="Phone" :error="form.errors.phone">
                        <TextInput v-model="form.phone" type="tel" />
                    </FormField>
                    <FormField class="sm:col-span-2" label="Email (login)" :error="form.errors.email" required>
                        <TextInput v-model="form.email" type="email" autocomplete="off" />
                    </FormField>
                    <FormField
                        label="Password"
                        :error="form.errors.password"
                        :required="!editing"
                        :hint="editing ? 'Leave blank to keep the current password.' : 'At least 8 characters.'"
                    >
                        <TextInput v-model="form.password" type="password" autocomplete="new-password" />
                    </FormField>
                    <FormField label="Confirm password" :error="form.errors.password_confirmation">
                        <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                    </FormField>
                    <FormField
                        label="POS PIN"
                        :error="form.errors.pin"
                        :hint="user?.has_pin ? 'A PIN is set. Leave blank to keep it.' : '4–6 digits for quick cashier switching on the POS.'"
                    >
                        <TextInput v-model="form.pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" />
                    </FormField>
                    <div class="flex items-end pb-2">
                        <Toggle v-model="form.is_active" label="Active" description="Inactive users cannot log in." />
                    </div>
                </div>
            </Card>

            <Card title="Access" description="The role decides what the user can do; branches decide where.">
                <div class="space-y-5">
                    <FormField label="Role" :error="form.errors.role" required>
                        <SelectInput v-model="form.role" :options="roleOptions" placeholder="Select a role…" />
                    </FormField>

                    <FormField label="Branches" :error="form.errors.branch_ids" :required="!isAdminRole">
                        <p v-if="isAdminRole" class="mb-2 text-xs text-gray-500">Admins can access every branch.</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="b in branches"
                                :key="b.id"
                                class="flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm"
                                :class="form.branch_ids.includes(b.id) ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200'"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    :checked="form.branch_ids.includes(b.id)"
                                    @change="toggleBranch(b.id)"
                                />
                                <span class="flex-1">{{ b.name }}</span>
                                <span class="font-mono text-xs text-gray-400">{{ b.code }}</span>
                            </label>
                        </div>
                    </FormField>
                </div>
            </Card>

            <div class="flex justify-end gap-3">
                <Link :href="route('users.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing">{{ editing ? 'Save changes' : 'Create user' }}</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
