<script setup>
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useCartStore } from '@/stores/cart';
import { money } from '@/utils/format';
import { reactive, ref, watch } from 'vue';

const props = defineProps({ item: { type: Object, default: null } });
const emit = defineEmits(['close']);

const { can } = usePermissions();
const cart = useCartStore();
const form = reactive({ quantity: 1, unit_price: '', discount_type: 'percent', discount_value: 0 });
const error = ref('');

watch(
    () => props.item,
    (item) => {
        if (!item) return;
        Object.assign(form, {
            quantity: item.quantity,
            unit_price: item.unit_price ?? '',
            discount_type: item.discount_type ?? 'percent',
            discount_value: item.discount_value ?? 0,
        });
        error.value = '';
    },
    { immediate: true },
);

const save = () => {
    const price = form.unit_price === '' ? null : Number(form.unit_price);
    if (price !== null && props.item.mrp && price > props.item.mrp) return (error.value = `Price can't be above MRP ${money(props.item.mrp)}.`);
    if (form.discount_type === 'percent' && Number(form.discount_value) > 100) return (error.value = 'A discount cannot be more than 100%.');
    if (!(Number(form.quantity) > 0)) return (error.value = 'Quantity must be more than zero.');
    Object.assign(props.item, {
        quantity: Number(form.quantity),
        unit_price: price === null || price === cart.listPriceOf(props.item) ? null : price,
        discount_type: form.discount_type,
        discount_value: Number(form.discount_value) || 0,
    });
    emit('close');
};
</script>

<template>
    <Modal :show="!!item" max-width="md" @close="emit('close')">
        <form v-if="item" class="space-y-4 p-6" @submit.prevent="save">
            <div>
                <h3 class="text-base font-semibold text-gray-900">{{ item.product_name }}</h3>
                <p class="text-sm text-gray-500">{{ item.variant_name }} · {{ item.sku }} · list {{ money(cart.listPriceOf(item)) }}<span v-if="item.mrp"> · MRP {{ money(item.mrp) }}</span></p>
            </div>
            <FormField :label="`Quantity (${item.unit})`">
                <TextInput v-model="form.quantity" type="number" :step="item.allow_decimal ? '0.001' : '1'" min="0" autofocus />
            </FormField>
            <FormField v-if="can('pos.price_override')" label="Price per unit (₹)" hint="Leave empty to use the list price.">
                <TextInput v-model="form.unit_price" type="number" step="0.01" min="0" :placeholder="String(cart.listPriceOf(item))" />
            </FormField>
            <FormField v-if="can('pos.discount')" label="Item discount">
                <div class="flex gap-2">
                    <select v-model="form.discount_type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="percent">%</option>
                        <option value="amount">₹ (on line)</option>
                    </select>
                    <TextInput v-model="form.discount_value" type="number" step="0.01" min="0" />
                </div>
            </FormField>
            <p v-if="!can('pos.discount') && !can('pos.price_override')" class="text-xs text-gray-500">Ask a manager to change price or give a discount.</p>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex justify-end gap-2">
                <SecondaryButton @click="emit('close')">Cancel</SecondaryButton>
                <PrimaryButton>Apply</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
