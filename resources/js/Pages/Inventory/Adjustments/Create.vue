<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import VariantPicker from '@/Components/VariantPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, qty } from '@/utils/format';
import { MinusIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    reasons: { type: Array, required: true },
    today: { type: String, required: true },
});

const form = useForm({
    date: props.today,
    reason: 'damage',
    mode: 'change',
    note: '',
    items: [], // { product_variant_id, name, sku, unit, allow_decimal, stock, avg_cost, direction, amount, counted, unit_cost }
});

// "Stock count correction" uses counted quantities; every other reason uses +/− changes.
watch(
    () => form.reason,
    (r) => (form.mode = r === 'count' ? 'count' : 'change'),
);
const isCount = computed(() => form.mode === 'count');
const defaultDirection = computed(() => (form.reason === 'found' ? 'in' : 'out'));

const add = (item) => {
    const existing = form.items.find((i) => i.product_variant_id === item.id);
    if (existing) {
        existing.amount = Number(existing.amount || 0) + 1;
        return;
    }
    form.items.push({
        product_variant_id: item.id,
        name: item.name,
        sku: item.sku,
        unit: item.unit,
        allow_decimal: item.allow_decimal,
        stock: item.stock,
        avg_cost: item.avg_cost || item.purchase_price,
        direction: defaultDirection.value,
        amount: 1,
        counted: item.stock > 0 ? item.stock : 0,
        unit_cost: '',
    });
};

const change = (line) => (isCount.value ? Number(line.counted || 0) - line.stock : (line.direction === 'in' ? 1 : -1) * Number(line.amount || 0));
const totalValue = computed(() => form.items.reduce((sum, l) => sum + change(l) * Number(l.unit_cost || l.avg_cost), 0));

const submit = () =>
    form
        .transform((data) => ({
            date: data.date,
            reason: data.reason,
            mode: data.mode,
            note: data.note,
            items: data.items.map((l) => ({
                product_variant_id: l.product_variant_id,
                ...(isCount.value ? { counted: l.counted } : { quantity: change(l) }),
                unit_cost: l.unit_cost === '' ? null : l.unit_cost,
            })),
        }))
        .post(route('stock-adjustments.store'));
useUnsavedGuard(() => form.items.length > 0 && !form.processing && !form.wasSuccessful);
</script>

<template>
    <AppLayout title="New stock adjustment">
        <PageHeader title="New stock adjustment" :description="`Branch: ${$page.props.branch?.current?.name}`" />

        <form class="space-y-6" @submit.prevent="submit">
            <Card>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <FormField label="Reason" :error="form.errors.reason" required>
                        <SelectInput v-model="form.reason" :options="reasons" />
                    </FormField>
                    <FormField label="Date" :error="form.errors.date" required>
                        <TextInput v-model="form.date" type="date" :max="today" />
                    </FormField>
                    <FormField label="Note" :error="form.errors.note">
                        <TextInput v-model="form.note" placeholder="Optional" />
                    </FormField>
                </div>
                <p v-if="isCount" class="mt-4 rounded-md bg-indigo-50 p-3 text-sm text-indigo-800">
                    Stock count: enter the quantity you physically counted. The difference from the system quantity is posted when you save.
                </p>
            </Card>

            <Card title="Items">
                <VariantPicker autofocus @select="add" />
                <InputError :message="form.errors.items" class="mt-2" />

                <div v-if="form.items.length" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="py-2 pr-3">Item</th>
                                <th class="px-3 py-2 text-right">In system</th>
                                <th class="px-3 py-2">{{ isCount ? 'Counted' : 'Change' }}</th>
                                <th class="px-3 py-2 text-right">Difference</th>
                                <th class="px-3 py-2">Unit cost</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, i) in form.items" :key="line.product_variant_id" class="border-t border-gray-100 align-top">
                                <td class="py-2 pr-3">
                                    <p class="font-medium text-gray-900">{{ line.name }}</p>
                                    <p class="font-mono text-xs text-gray-500">{{ line.sku }}</p>
                                    <InputError :message="form.errors[`items.${i}.product_variant_id`]" />
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ qty(line.stock) }} {{ line.unit }}</td>
                                <td class="px-3 py-2">
                                    <div v-if="isCount" class="w-28">
                                        <TextInput v-model="line.counted" type="number" min="0" :step="line.allow_decimal ? '0.001' : '1'" />
                                    </div>
                                    <div v-else class="flex items-center gap-1">
                                        <div class="inline-flex rounded-md border border-gray-300 p-0.5">
                                            <button
                                                type="button"
                                                class="rounded p-1"
                                                :class="line.direction === 'in' ? 'bg-green-600 text-white' : 'text-gray-500'"
                                                title="Add stock"
                                                @click="line.direction = 'in'"
                                            >
                                                <PlusIcon class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded p-1"
                                                :class="line.direction === 'out' ? 'bg-red-600 text-white' : 'text-gray-500'"
                                                title="Remove stock"
                                                @click="line.direction = 'out'"
                                            >
                                                <MinusIcon class="h-4 w-4" />
                                            </button>
                                        </div>
                                        <TextInput v-model="line.amount" type="number" min="0" :step="line.allow_decimal ? '0.001' : '1'" class="!w-24" />
                                    </div>
                                    <InputError :message="form.errors[`items.${i}.quantity`] ?? form.errors[`items.${i}.counted`]" />
                                </td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums" :class="change(line) < 0 ? 'text-red-600' : change(line) > 0 ? 'text-green-700' : 'text-gray-400'">
                                    {{ change(line) > 0 ? '+' : '' }}{{ qty(change(line)) }}
                                    <p v-if="line.stock + change(line) < 0" class="text-xs font-normal text-red-600">More than in stock</p>
                                </td>
                                <td class="px-3 py-2">
                                    <TextInput
                                        v-if="change(line) > 0"
                                        v-model="line.unit_cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="!w-28"
                                        :placeholder="money(line.avg_cost)"
                                    />
                                    <span v-else class="text-xs text-gray-500">{{ money(line.avg_cost) }} (avg)</span>
                                </td>
                                <td class="py-2 pl-2">
                                    <button type="button" class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-red-600" @click="form.items.splice(i, 1)">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 font-medium">
                                <td class="py-2" colspan="3">Value impact</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="totalValue < 0 ? 'text-red-600' : 'text-green-700'">{{ money(totalValue) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else class="mt-4 text-sm text-gray-500">Scan or search items to add them.</p>
            </Card>

            <div class="flex justify-end gap-3">
                <Link :href="route('stock-adjustments.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing || !form.items.length">Save adjustment</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
