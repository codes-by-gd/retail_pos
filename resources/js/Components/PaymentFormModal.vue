<script setup>
/**
 * Receive money from a customer (direction "in") or pay a supplier ("out").
 * Allocated by the server to the oldest unpaid bills; the rest stays as advance.
 */
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { money } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    contact: { type: Object, required: true }, // { id, name }
    direction: { type: String, required: true }, // in | out
    suggested: { type: Number, default: 0 },
    methods: { type: Array, required: true },
    registers: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const today = () => new Date().toLocaleDateString('en-CA');
const form = useForm({ direction: props.direction, amount: '', method: 'cash', reference: '', date: today(), note: '', register_session_id: null });

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        Object.assign(form, {
            direction: props.direction,
            amount: props.suggested > 0 ? props.suggested : '',
            method: 'cash',
            date: today(),
            register_session_id: props.registers[0]?.value ?? null,
        });
    },
);

const submit = () =>
    form.post(route('contacts.payments.store', props.contact.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="submit">
            <div>
                <h3 class="text-base font-semibold text-gray-900">{{ direction === 'in' ? 'Receive payment' : 'Pay supplier' }}</h3>
                <p class="text-sm text-gray-500">
                    {{ contact.name }}<span v-if="suggested > 0"> · unpaid {{ money(suggested) }}</span>
                </p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <FormField label="Amount (₹)" :error="form.errors.amount" required>
                    <TextInput v-model="form.amount" type="number" min="0" step="0.01" autofocus />
                </FormField>
                <FormField label="Date" :error="form.errors.date" required>
                    <TextInput v-model="form.date" type="date" :max="today()" />
                </FormField>
                <FormField label="Mode" :error="form.errors.method" required>
                    <SelectInput v-model="form.method" :options="methods" />
                </FormField>
                <FormField label="Reference" :error="form.errors.reference" hint="UTR / cheque no.">
                    <TextInput v-model="form.reference" />
                </FormField>
            </div>
            <FormField v-if="form.method === 'cash'" label="Cash drawer" hint="Cash through a register counts in its Z-report.">
                <SelectInput v-model="form.register_session_id" :options="registers" placeholder="Not through a register (safe / bank)" />
            </FormField>
            <FormField label="Note" :error="form.errors.note"><TextInput v-model="form.note" placeholder="Optional" /></FormField>
            <p class="text-xs text-gray-500">Settles the oldest unpaid bills first; any extra stays as {{ direction === 'in' ? 'advance from the customer' : 'advance to the supplier' }}.</p>
            <div class="flex justify-end gap-2">
                <SecondaryButton @click="emit('close')">Cancel</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ direction === 'in' ? 'Receive' : 'Pay' }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
