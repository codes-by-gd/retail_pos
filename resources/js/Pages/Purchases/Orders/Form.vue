<script setup>
import Card from '@/Components/Card.vue';
import ContactPicker from '@/Components/ContactPicker.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import VariantPicker from '@/Components/VariantPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, qty } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ order: { type: Object, default: null }, today: { type: String, required: true } });

const supplier = ref(props.order?.supplier ?? null);
const lines = ref(props.order?.items ?? []);
const form = useForm({ order_date: props.order?.order_date ?? props.today, expected_date: props.order?.expected_date ?? '', notes: props.order?.notes ?? '', place: false });

const add = (item) => {
    const existing = lines.value.find((l) => l.id === item.id);
    if (existing) return existing.quantity++;
    lines.value.push({ ...item, quantity: 1, unit_cost: item.purchase_price });
};
const total = computed(() => lines.value.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0));

const save = (place) => {
    form.place = place;
    form.transform((d) => ({
        ...d,
        supplier_id: supplier.value?.id ?? null,
        expected_date: d.expected_date || null,
        items: lines.value.map((l) => ({ variant_id: l.id, quantity: l.quantity, unit_cost: l.unit_cost })),
    }));
    props.order ? form.put(route('purchase-orders.update', props.order.id)) : form.post(route('purchase-orders.store'));
};
</script>

<template>
    <AppLayout :title="order ? `Edit ${order.reference_no}` : 'New purchase order'">
        <PageHeader :title="order ? `Edit order ${order.reference_no}` : 'New purchase order'" />
        <form class="space-y-6" @submit.prevent="save(true)">
            <Card>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <FormField class="md:col-span-2" label="Supplier" :error="form.errors.supplier_id" required><ContactPicker v-model="supplier" kind="supplier" /></FormField>
                    <FormField label="Order date" :error="form.errors.order_date" required><TextInput v-model="form.order_date" type="date" /></FormField>
                    <FormField label="Expected delivery" :error="form.errors.expected_date"><TextInput v-model="form.expected_date" type="date" /></FormField>
                </div>
            </Card>
            <Card title="Items">
                <VariantPicker placeholder="Search item, article no. or scan…" @select="add" />
                <InputError :message="form.errors.items" class="mt-2" />
                <table v-if="lines.length" class="mt-4 min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase text-gray-500">
                        <tr><th class="py-2">Item</th><th class="px-2 py-2 text-right">In stock</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">Rate (excl. GST)</th><th class="px-2 py-2 text-right">Amount</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in lines" :key="l.id" class="border-t border-gray-100 align-top">
                            <td class="py-2"><p class="font-medium">{{ l.product_name }}</p><p class="text-xs text-gray-500">{{ l.variant_name }} · {{ l.sku }}</p></td>
                            <td class="px-2 py-2 text-right text-gray-500">{{ qty(l.stock) }} {{ l.unit }}</td>
                            <td class="px-2 py-2"><TextInput v-model.number="l.quantity" type="number" min="0" class="!w-24" /><InputError :message="form.errors[`items.${i}.quantity`]" /></td>
                            <td class="px-2 py-2"><TextInput v-model.number="l.unit_cost" type="number" min="0" step="0.01" class="!w-28" /></td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ money(Number(l.quantity || 0) * Number(l.unit_cost || 0)) }}</td>
                            <td class="py-2 pl-2"><button type="button" class="p-1.5 text-gray-400 hover:text-red-600" @click="lines.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t border-gray-200 font-semibold"><td colspan="4" class="py-2 text-right">Estimated value (excl. GST)</td><td class="px-2 py-2 text-right">{{ money(total) }}</td><td></td></tr></tfoot>
                </table>
            </Card>
            <Card title="Notes"><TextareaInput v-model="form.notes" rows="2" placeholder="Colour shades, packing, delivery instructions…" /></Card>
            <div class="flex justify-end gap-3">
                <Link :href="order ? route('purchase-orders.show', order.id) : route('purchase-orders.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <SecondaryButton :disabled="form.processing || !lines.length" @click="save(false)">Save draft</SecondaryButton>
                <PrimaryButton :disabled="form.processing || !lines.length">Save & place order</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
