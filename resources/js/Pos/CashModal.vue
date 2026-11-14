<script setup>
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { money } from '@/utils/format';
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import { apiError } from './helpers';

const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const form = reactive({ type: 'out', amount: '', reason: '' });
const error = ref('');
const done = ref(null);

watch(
    () => props.show,
    (open) => open && (Object.assign(form, { type: 'out', amount: '', reason: '' }), (error.value = ''), (done.value = null)),
);

const save = async () => {
    error.value = '';
    try {
        done.value = (await axios.post(route('pos.cash-movements.store'), form)).data.expected_cash;
    } catch (e) {
        error.value = apiError(e);
    }
};
</script>

<template>
    <Modal :show="show" max-width="sm" @close="emit('close')">
        <div class="p-6">
            <h3 class="mb-4 text-base font-semibold text-gray-900">Cash in / out</h3>
            <div v-if="done !== null" class="space-y-4 text-center">
                <p class="text-sm text-gray-600">Saved. Cash expected in drawer now:</p>
                <p class="text-2xl font-bold">{{ money(done) }}</p>
                <PrimaryButton type="button" @click="emit('close')">Done</PrimaryButton>
            </div>
            <form v-else class="space-y-4" @submit.prevent="save">
                <div class="inline-flex rounded-md border border-gray-300 p-0.5 text-sm">
                    <button v-for="t in [{ v: 'out', l: 'Cash out' }, { v: 'in', l: 'Cash in' }]" :key="t.v" type="button" class="rounded px-3 py-1"
                        :class="form.type === t.v ? 'bg-indigo-600 text-white' : 'text-gray-600'" @click="form.type = t.v">{{ t.l }}</button>
                </div>
                <FormField label="Amount (₹)" required><TextInput v-model="form.amount" type="number" min="0" step="0.01" autofocus /></FormField>
                <FormField label="Reason" required><TextInput v-model="form.reason" :placeholder="form.type === 'out' ? 'e.g. Tea & snacks, bank deposit' : 'e.g. Change float top-up'" /></FormField>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton @click="emit('close')">Cancel</SecondaryButton>
                    <PrimaryButton>Save</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
