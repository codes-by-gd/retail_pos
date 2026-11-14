<script setup>
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useCartStore } from '@/stores/cart';
import { money } from '@/utils/format';
import { reactive, ref, watch } from 'vue';

const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const cart = useCartStore();
const form = reactive({ type: 'percent', value: 0 });
const error = ref('');

watch(
    () => props.show,
    (open) => {
        if (open) Object.assign(form, { type: cart.billDiscount.type ?? 'percent', value: cart.billDiscount.value ?? 0 }), (error.value = '');
    },
);

const apply = () => {
    if (form.type === 'percent' && Number(form.value) > 100) return (error.value = 'Cannot be more than 100%.');
    cart.billDiscount = { type: Number(form.value) > 0 ? form.type : null, value: Number(form.value) || 0 };
    emit('close');
};
</script>

<template>
    <Modal :show="show" max-width="sm" @close="emit('close')">
        <form class="space-y-4 p-6" @submit.prevent="apply">
            <h3 class="text-base font-semibold text-gray-900">Bill discount</h3>
            <div class="flex gap-2">
                <select v-model="form.type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="percent">%</option>
                    <option value="amount">₹</option>
                </select>
                <TextInput v-model="form.value" type="number" min="0" step="0.01" autofocus @focus="$event.target.select()" />
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="p in [5, 10, 15, 20]" :key="p" type="button" class="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50" @click="(form.type = 'percent'), (form.value = p)">
                    {{ p }}%
                </button>
            </div>
            <p class="text-xs text-gray-500">
                Shared across items in proportion to their value. Subtotal after item discounts:
                {{ money(cart.preview.totals.subtotal - cart.preview.totals.line_discount_total) }}
            </p>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex justify-end gap-2">
                <SecondaryButton @click="(form.value = 0), apply()">Remove</SecondaryButton>
                <PrimaryButton>Apply</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
