<script setup>
import FormField from '@/Components/FormField.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/utils/format';
import { ComputerDesktopIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    registers: { type: Array, required: true },
    canOpen: { type: Boolean, default: false },
    recallId: { type: Number, default: null },
});

const form = useForm({
    register_id: props.registers.find((r) => r.open_session)?.id ?? props.registers[0]?.id ?? null,
    opening_cash: '',
    recall: props.recallId,
});
const selected = () => props.registers.find((r) => r.id === form.register_id);
const submit = () => form.post(route('pos.register.open'));
</script>

<template>
    <AppLayout title="POS">
        <div class="mx-auto max-w-xl">
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <ComputerDesktopIcon class="h-10 w-10 text-indigo-600" />
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Start billing</h2>
                        <p class="text-sm text-gray-500">Choose your counter. {{ $page.props.branch?.current?.name }}</p>
                    </div>
                </div>

                <p v-if="!registers.length" class="mt-6 rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                    This branch has no active register.
                    <Link v-if="$page.props.auth.user.permissions.includes('settings.manage')" :href="route('settings.registers.index')" class="font-medium underline">Add one</Link>
                    <span v-else>Ask an admin to add one.</span>
                </p>

                <form v-else class="mt-6 space-y-5" @submit.prevent="submit">
                    <p v-if="recallId" class="rounded-md bg-indigo-50 p-3 text-sm text-indigo-800">The quotation's bill opens as soon as the register is open.</p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label
                            v-for="r in registers"
                            :key="r.id"
                            class="flex cursor-pointer flex-col rounded-lg border p-3"
                            :class="form.register_id === r.id ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-gray-200 hover:border-gray-300'"
                        >
                            <span class="flex items-center gap-2">
                                <input v-model="form.register_id" type="radio" :value="r.id" class="text-indigo-600 focus:ring-indigo-500" />
                                <span class="font-medium text-gray-900">{{ r.name }}</span>
                            </span>
                            <span class="mt-1 text-xs" :class="r.open_session ? 'text-green-700' : 'text-gray-500'">
                                {{ r.open_session ? `Open since ${dateTime(r.open_session.opened_at)} by ${r.open_session.opened_by}` : 'Closed' }}
                            </span>
                        </label>
                    </div>

                    <FormField
                        v-if="selected() && !selected().open_session"
                        label="Opening cash in drawer (₹)"
                        :error="form.errors.opening_cash"
                        hint="Count the change float before you start."
                    >
                        <TextInput v-model="form.opening_cash" type="number" min="0" step="0.01" autofocus />
                    </FormField>

                    <p v-if="selected() && !selected().open_session && !canOpen" class="text-sm text-amber-700">Ask a manager to open this register.</p>
                    <p v-if="form.errors.register_id" class="text-sm text-red-600">{{ form.errors.register_id }}</p>

                    <PrimaryButton class="w-full !py-3" :disabled="form.processing || !selected() || (!selected().open_session && !canOpen)">
                        {{ selected()?.open_session ? 'Join open register' : 'Open register' }}
                    </PrimaryButton>
                </form>
            </div>
            <p class="mt-4 text-center text-sm">
                <Link v-if="$page.props.auth.user.permissions.includes('pos.register.manage')" :href="route('register-sessions.index')" class="text-indigo-600 hover:text-indigo-800">Register shift history</Link>
            </p>
        </div>
    </AppLayout>
</template>
