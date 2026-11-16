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
import { qty } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';

defineProps({ destinations: { type: Array, required: true } });

const form = useForm({ to_branch_id: null, note: '', items: [] });

const add = (item) => {
    const existing = form.items.find((i) => i.product_variant_id === item.id);
    if (existing) {
        existing.quantity = Number(existing.quantity || 0) + 1;
        return;
    }
    form.items.push({ product_variant_id: item.id, name: item.name, sku: item.sku, unit: item.unit, allow_decimal: item.allow_decimal, stock: item.stock, quantity: 1 });
};

const submit = () =>
    form
        .transform((d) => ({ ...d, items: d.items.map((l) => ({ product_variant_id: l.product_variant_id, quantity: l.quantity })) }))
        .post(route('stock-transfers.store'));
useUnsavedGuard(() => form.items.length > 0 && !form.processing && !form.wasSuccessful);
</script>

<template>
    <AppLayout title="New stock transfer">
        <PageHeader title="New stock transfer" :description="`Sending from ${$page.props.branch?.current?.name}`" />

        <div v-if="!destinations.length" class="rounded-md bg-amber-50 p-4 text-sm text-amber-800">
            There is no other active branch to transfer to. Add a branch under Administration → Branches.
        </div>

        <form v-else class="space-y-6" @submit.prevent="submit">
            <Card>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Send to" :error="form.errors.to_branch_id" required>
                        <SelectInput v-model="form.to_branch_id" :options="destinations" placeholder="Choose branch…" />
                    </FormField>
                    <FormField label="Note" :error="form.errors.note">
                        <TextInput v-model="form.note" placeholder="Optional, e.g. vehicle no." />
                    </FormField>
                </div>
            </Card>

            <Card title="Items">
                <VariantPicker scan-mode="continuous" autofocus @select="add" />
                <InputError :message="form.errors.items" class="mt-2" />

                <table v-if="form.items.length" class="mt-4 min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="py-2 pr-3">Item</th>
                            <th class="px-3 py-2 text-right">Available</th>
                            <th class="px-3 py-2">Quantity</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, i) in form.items" :key="line.product_variant_id" class="border-t border-gray-100 align-top">
                            <td class="py-2 pr-3">
                                <p class="font-medium text-gray-900">{{ line.name }}</p>
                                <p class="font-mono text-xs text-gray-500">{{ line.sku }}</p>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ qty(line.stock) }} {{ line.unit }}</td>
                            <td class="px-3 py-2">
                                <TextInput v-model="line.quantity" type="number" min="0" :step="line.allow_decimal ? '0.001' : '1'" class="!w-28" />
                                <p v-if="Number(line.quantity) > line.stock" class="text-xs text-red-600">More than available</p>
                                <InputError :message="form.errors[`items.${i}.quantity`]" />
                            </td>
                            <td class="py-2 pl-2 text-right">
                                <button type="button" class="rounded-sm p-2 text-gray-400 hover:bg-gray-100 hover:text-red-600" @click="form.items.splice(i, 1)">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-4 text-sm text-gray-500">Scan or search items to add them.</p>
            </Card>

            <div class="flex justify-end gap-3">
                <Link :href="route('stock-transfers.index')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</Link>
                <PrimaryButton :disabled="form.processing || !form.items.length">Send transfer</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
