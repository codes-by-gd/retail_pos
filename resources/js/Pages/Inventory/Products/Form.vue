<script setup>
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money } from '@/utils/format';
import { PhotoIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, default: null },
    categories: { type: Array, required: true },
    brands: { type: Array, required: true },
    units: { type: Array, required: true },
    taxRates: { type: Array, required: true },
    attributes: { type: Array, required: true }, // [{id, name, values: [{id, value}]}]
    materials: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
    currentBranch: { type: String, default: null },
});

const editing = computed(() => !!props.product);

const priceFields = () => ({ purchase_price: '', mrp: '', selling_price: '', wholesale_price: '', reorder_level: 0 });
const blankVariant = (options = {}) => ({
    id: null,
    options,
    sku: '',
    barcodes: '',
    ...priceFields(),
    is_active: true,
    opening_stock: '',
});

const form = useForm({
    name: props.product?.name ?? '',
    article_no: props.product?.article_no ?? '',
    material: props.product?.material ?? '',
    type: props.product?.type ?? 'variable',
    category_id: props.product?.category_id ?? null,
    brand_id: props.product?.brand_id ?? null,
    unit_id: props.product?.unit_id ?? props.defaults.unit_id,
    tax_rate_id: props.product?.tax_rate_id ?? props.defaults.tax_rate_id,
    hsn_code: props.product?.hsn_code ?? '',
    price_includes_tax: props.product?.price_includes_tax ?? props.defaults.price_includes_tax,
    track_stock: props.product?.track_stock ?? true,
    description: props.product?.description ?? '',
    is_active: props.product?.is_active ?? true,
    image: null,
    remove_image: false,
    variants: props.product?.variants?.map((v) => ({
        ...blankVariant(),
        ...v,
        options: { ...(v.options ?? {}) },
        mrp: v.mrp ?? '',
        wholesale_price: v.wholesale_price ?? '',
    })) ?? [], // new products start as sizes/colours with no rows until values are picked
});

/* ---------- image ---------- */
const imagePreview = ref(props.product?.image_url ?? null);
const onImage = (e) => {
    const file = e.target.files[0];
    form.image = file ?? null;
    form.remove_image = false;
    imagePreview.value = file ? URL.createObjectURL(file) : props.product?.image_url ?? null;
};
const removeImage = () => {
    form.image = null;
    form.remove_image = true;
    imagePreview.value = null;
};

const isVariable = computed(() => form.type === 'variable');
const unit = computed(() => props.units.find((u) => u.value === form.unit_id));
const qtyStep = computed(() => (unit.value?.allow_decimal ? '0.001' : '1'));

/* ---------- size / colour matrix ---------- */
// Selected attributes, in display order, each with its chosen values (strings).
const existingVariants = props.product?.type === 'variable' ? form.variants.map((v) => ({ ...v })) : [];
const firstOptions = existingVariants[0]?.options ?? {};
const selection = reactive(
    props.attributes
        .filter((a) => String(a.id) in firstOptions)
        .map((a) => ({
            id: a.id,
            values: [...new Set(existingVariants.map((v) => v.options[a.id]))].sort(
                (x, y) => a.values.findIndex((o) => o.value === x) - a.values.findIndex((o) => o.value === y),
            ),
        })),
);
const extraValues = reactive({}); // attribute id → values typed in this form (not saved yet)
const newValue = reactive({});
const excluded = ref(new Set()); // combination keys the user removed
const defaults = reactive(priceFields());

const attributeById = (id) => props.attributes.find((a) => a.id === id);
const valuesFor = (id) => [...(attributeById(id)?.values.map((v) => v.value) ?? []), ...(extraValues[id] ?? [])];
const isSelected = (id) => selection.some((s) => s.id === id);

const toggleAttribute = (id) => {
    const i = selection.findIndex((s) => s.id === id);
    if (i >= 0) selection.splice(i, 1);
    else if (selection.length < 3) {
        selection.push({ id, values: [] });
        selection.sort((a, b) => props.attributes.findIndex((x) => x.id === a.id) - props.attributes.findIndex((x) => x.id === b.id));
    }
};
const toggleValue = (sel, value) => {
    const i = sel.values.indexOf(value);
    if (i >= 0) sel.values.splice(i, 1);
    else {
        const order = valuesFor(sel.id);
        sel.values = [...sel.values, value].sort((x, y) => order.indexOf(x) - order.indexOf(y));
    }
};
const addValue = (sel) => {
    const v = (newValue[sel.id] ?? '').trim();
    newValue[sel.id] = '';
    if (!v) return;
    const known = valuesFor(sel.id).find((x) => x.toLowerCase() === v.toLowerCase());
    if (!known) extraValues[sel.id] = [...(extraValues[sel.id] ?? []), v];
    if (!sel.values.includes(known ?? v)) toggleValue(sel, known ?? v);
};

const keyOf = (options) =>
    Object.keys(options)
        .sort()
        .map((k) => `${k}=${String(options[k]).toLowerCase()}`)
        .join('|');
const labelOf = (options) => selection.map((s) => options[s.id]).join(' / ');

// All combinations of the selected values.
const combinations = computed(() => {
    if (!selection.length || selection.some((s) => !s.values.length)) return [];
    return selection.reduce((acc, s) => acc.flatMap((combo) => s.values.map((v) => ({ ...combo, [s.id]: v }))), [{}]);
});

// Rebuild the variant rows whenever the selection changes, keeping what was typed per combination.
const rowCache = new Map(existingVariants.map((v) => [keyOf(v.options), v]));
watch(
    [combinations, excluded],
    () => {
        if (!isVariable.value) return;
        form.variants.forEach((v) => rowCache.set(keyOf(v.options), v));
        form.variants = combinations.value
            .filter((options) => !excluded.value.has(keyOf(options)))
            .map((options) => rowCache.get(keyOf(options)) ?? { ...blankVariant(options), ...defaults });
    },
    { deep: true },
);

const removedExisting = computed(() => {
    const kept = new Set(form.variants.map((v) => v.id).filter(Boolean));
    return existingVariants.filter((v) => !kept.has(v.id));
});

const excludeRow = (v) => (excluded.value = new Set([...excluded.value, keyOf(v.options)]));
const applyDefaults = () =>
    form.variants.forEach((v) => Object.entries(defaults).forEach(([k, val]) => val !== '' && val !== null && (v[k] = val)));

const setType = (type) => {
    if (type === form.type) return;
    form.type = type;
    if (type === 'single') {
        const first = form.variants[0] ?? blankVariant();
        form.variants = [{ ...first, options: {} }];
    } else {
        Object.assign(defaults, { ...priceFields(), ...Object.fromEntries(Object.keys(priceFields()).map((k) => [k, form.variants[0]?.[k] ?? ''])) });
        form.variants = [];
        selection.splice(0);
    }
};

/* ---------- helpers ---------- */
const single = computed(() => form.variants[0]);
const margin = (v) => {
    const sp = Number(v.selling_price);
    const pp = Number(v.purchase_price);
    if (!sp || !pp) return null;
    return (((sp - pp) / sp) * 100).toFixed(1);
};
const err = (i, field) => form.errors[`variants.${i}.${field}`] ?? (field === 'barcodes' ? form.errors[`variants.${i}.barcodes.0`] : null);
const rowError = (i) => form.errors[`variants.${i}.options`] ?? err(i, 'sku') ?? err(i, 'barcodes') ?? err(i, 'purchase_price') ?? err(i, 'mrp') ?? err(i, 'selling_price');

const submit = () => {
    // 'indices' encodes variants[0][options][3]; the default 'brackets' would split each field into its own row.
    const options = { forceFormData: true, preserveScroll: true, queryStringArrayFormat: 'indices' };
    if (editing.value) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('products.update', props.product.id), options);
    } else {
        form.post(route('products.store'), options);
    }
};
</script>

<template>
    <AppLayout :title="editing ? 'Edit product' : 'New product'">
        <PageHeader :title="editing ? `Edit ${product.name}` : 'New product'" />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-6 lg:grid-cols-3">
                <Card title="Details" class="lg:col-span-2">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField class="sm:col-span-2" label="Product name" :error="form.errors.name" required>
                            <TextInput v-model="form.name" autofocus placeholder="e.g. Slim Fit Formal Shirt" />
                        </FormField>
                        <FormField label="Article / style no." :error="form.errors.article_no" hint="The brand's code printed on the tag">
                            <TextInput v-model="form.article_no" placeholder="e.g. PE-SH-1042" />
                        </FormField>
                        <FormField label="Fabric / material" :error="form.errors.material">
                            <TextInput v-model="form.material" list="materials" placeholder="e.g. Cotton" />
                            <datalist id="materials">
                                <option v-for="m in materials" :key="m" :value="m" />
                            </datalist>
                        </FormField>
                        <FormField label="Category" :error="form.errors.category_id">
                            <SelectInput v-model="form.category_id" :options="categories" placeholder="— None —" />
                        </FormField>
                        <FormField label="Brand" :error="form.errors.brand_id">
                            <SelectInput v-model="form.brand_id" :options="brands" placeholder="— None —" />
                        </FormField>
                        <FormField label="Unit" :error="form.errors.unit_id" required>
                            <SelectInput v-model="form.unit_id" :options="units" />
                        </FormField>
                        <FormField label="HSN code" :error="form.errors.hsn_code" hint="Garments: chapter 61 (knitted) / 62 (woven)">
                            <TextInput v-model="form.hsn_code" inputmode="numeric" maxlength="8" />
                        </FormField>
                        <FormField label="GST" :error="form.errors.tax_rate_id" required>
                            <SelectInput v-model="form.tax_rate_id" :options="taxRates" />
                        </FormField>
                        <div class="flex items-end pb-1">
                            <Toggle
                                v-model="form.price_includes_tax"
                                label="Prices include GST"
                                description="On for MRP-style pricing; off to add GST on top."
                            />
                        </div>
                        <FormField class="sm:col-span-2" label="Description" :error="form.errors.description">
                            <TextareaInput v-model="form.description" rows="2" placeholder="Fit, wash care, pattern…" />
                        </FormField>
                    </div>
                </Card>

                <div class="space-y-6">
                    <Card title="Image">
                        <div class="flex items-center gap-4">
                            <img v-if="imagePreview" :src="imagePreview" alt="" class="h-24 w-24 rounded-md border object-cover" />
                            <div v-else class="flex h-24 w-24 items-center justify-center rounded-md border border-dashed text-gray-300">
                                <PhotoIcon class="h-8 w-8" />
                            </div>
                            <div class="space-y-2 text-sm">
                                <label class="cursor-pointer font-medium text-indigo-600 hover:text-indigo-800">
                                    {{ imagePreview ? 'Change' : 'Upload' }}
                                    <input type="file" accept="image/*" class="hidden" @change="onImage" />
                                </label>
                                <button v-if="imagePreview" type="button" class="block text-red-600 hover:text-red-800" @click="removeImage">Remove</button>
                                <p class="text-xs text-gray-500">JPG/PNG, max 2 MB</p>
                            </div>
                        </div>
                        <InputError :message="form.errors.image" class="mt-2" />
                    </Card>

                    <Card title="Settings">
                        <div class="space-y-4">
                            <Toggle v-model="form.track_stock" label="Track stock" description="Turn off for services like alterations." />
                            <Toggle v-model="form.is_active" label="Active" description="Inactive products can't be sold." />
                        </div>
                    </Card>
                </div>
            </div>

            <Card title="Sizes, colours & prices">
                <template #actions>
                    <div class="inline-flex rounded-md border border-gray-300 p-0.5 text-sm">
                        <button
                            v-for="t in [{ v: 'variable', l: 'Sizes / colours' }, { v: 'single', l: 'Single item' }]"
                            :key="t.v"
                            type="button"
                            class="rounded px-3 py-1"
                            :class="form.type === t.v ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                            @click="setType(t.v)"
                        >
                            {{ t.l }}
                        </button>
                    </div>
                </template>

                <InputError :message="form.errors.variants" class="mb-3" />

                <!-- Single item (sarees, fabric by metre, accessories, services) -->
                <div v-if="!isVariable" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Purchase price (₹)" :error="err(0, 'purchase_price')" required>
                        <TextInput v-model="single.purchase_price" type="number" step="0.01" min="0" />
                    </FormField>
                    <FormField label="MRP (₹)" :error="err(0, 'mrp')">
                        <TextInput v-model="single.mrp" type="number" step="0.01" min="0" />
                    </FormField>
                    <FormField
                        label="Selling price (₹)"
                        :error="err(0, 'selling_price')"
                        required
                        :hint="margin(single) !== null ? `Margin ${margin(single)}%` : ''"
                    >
                        <TextInput v-model="single.selling_price" type="number" step="0.01" min="0" />
                    </FormField>
                    <FormField label="Wholesale price (₹)" :error="err(0, 'wholesale_price')">
                        <TextInput v-model="single.wholesale_price" type="number" step="0.01" min="0" />
                    </FormField>
                    <FormField label="SKU" :error="err(0, 'sku')" hint="Leave blank to auto-generate">
                        <TextInput v-model="single.sku" />
                    </FormField>
                    <FormField label="Barcode(s)" :error="err(0, 'barcodes')" hint="Comma separated; blank = auto EAN-13">
                        <TextInput v-model="single.barcodes" placeholder="Scan or type" />
                    </FormField>
                    <FormField v-if="form.track_stock" label="Reorder level" :error="err(0, 'reorder_level')" hint="Low-stock alert at this quantity">
                        <TextInput v-model="single.reorder_level" type="number" :step="qtyStep" min="0" />
                    </FormField>
                    <FormField
                        v-if="!editing && form.track_stock"
                        label="Opening stock"
                        :error="err(0, 'opening_stock')"
                        :hint="`Added to ${currentBranch ?? 'current branch'} at purchase price`"
                    >
                        <TextInput v-model="single.opening_stock" type="number" :step="qtyStep" min="0" />
                    </FormField>
                </div>

                <!-- Size / colour matrix -->
                <div v-else class="space-y-5">
                    <div>
                        <p class="mb-2 text-sm font-medium text-gray-700">1. Varies by <span class="font-normal text-gray-500">(up to 3)</span></p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="a in attributes"
                                :key="a.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm"
                                :class="isSelected(a.id) ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 text-gray-700 hover:border-indigo-400'"
                                @click="toggleAttribute(a.id)"
                            >
                                {{ a.name }}
                            </button>
                        </div>
                    </div>

                    <div v-for="sel in selection" :key="sel.id">
                        <p class="mb-2 text-sm font-medium text-gray-700">
                            {{ attributeById(sel.id).name }} <span class="font-normal text-gray-500">— {{ sel.values.length }} selected</span>
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                v-for="v in valuesFor(sel.id)"
                                :key="v"
                                type="button"
                                class="rounded-md border px-2.5 py-1 text-sm"
                                :class="sel.values.includes(v) ? 'border-indigo-500 bg-indigo-50 font-medium text-indigo-800' : 'border-gray-200 text-gray-600 hover:border-gray-400'"
                                @click="toggleValue(sel, v)"
                            >
                                {{ v }}
                            </button>
                            <input
                                v-model="newValue[sel.id]"
                                type="text"
                                placeholder="+ new value"
                                class="w-28 rounded-md border-dashed border-gray-300 px-2 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                @keydown.enter.prevent="addValue(sel)"
                                @blur="addValue(sel)"
                            />
                        </div>
                    </div>

                    <div v-if="selection.length" class="rounded-md bg-gray-50 p-4">
                        <p class="mb-2 text-sm font-medium text-gray-700">2. Default prices for new combinations</p>
                        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                            <FormField label="Purchase ₹"><TextInput v-model="defaults.purchase_price" type="number" step="0.01" min="0" /></FormField>
                            <FormField label="MRP ₹"><TextInput v-model="defaults.mrp" type="number" step="0.01" min="0" /></FormField>
                            <FormField label="Selling ₹"><TextInput v-model="defaults.selling_price" type="number" step="0.01" min="0" /></FormField>
                            <FormField label="Wholesale ₹"><TextInput v-model="defaults.wholesale_price" type="number" step="0.01" min="0" /></FormField>
                            <FormField v-if="form.track_stock" label="Reorder at"><TextInput v-model="defaults.reorder_level" type="number" min="0" /></FormField>
                            <div class="flex items-end">
                                <SecondaryButton class="w-full" :disabled="!form.variants.length" @click="applyDefaults">Apply to all rows</SecondaryButton>
                            </div>
                        </div>
                    </div>

                    <div v-if="form.variants.length">
                        <p class="mb-2 text-sm font-medium text-gray-700">3. {{ form.variants.length }} variants</p>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <th class="sticky left-0 z-10 bg-white py-2 pr-2">Variant</th>
                                        <th class="px-2 py-2">SKU</th>
                                        <th class="px-2 py-2">Barcode(s)</th>
                                        <th class="px-2 py-2">Purchase *</th>
                                        <th class="px-2 py-2">MRP</th>
                                        <th class="px-2 py-2">Selling *</th>
                                        <th class="px-2 py-2">Wholesale</th>
                                        <th v-if="form.track_stock" class="px-2 py-2">Reorder</th>
                                        <th v-if="!editing && form.track_stock" class="px-2 py-2">Opening</th>
                                        <th class="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="align-top">
                                    <tr v-for="(v, i) in form.variants" :key="keyOf(v.options)" class="border-t border-gray-100">
                                        <td class="sticky left-0 z-10 whitespace-nowrap bg-white py-2 pr-3 pt-4 font-medium text-gray-900 shadow-[1px_0_0_#f3f4f6]">
                                            {{ labelOf(v.options) }}
                                            <span v-if="!v.id && editing" class="ml-1 rounded bg-green-50 px-1 text-xs font-normal text-green-700">new</span>
                                            <InputError :message="rowError(i)" class="max-w-[12rem] whitespace-normal font-normal" />
                                        </td>
                                        <td class="px-2 py-2"><TextInput v-model="v.sku" class="min-w-[7rem]" placeholder="auto" /></td>
                                        <td class="px-2 py-2"><TextInput v-model="v.barcodes" class="min-w-[9rem]" placeholder="auto" /></td>
                                        <td class="px-2 py-2"><TextInput v-model="v.purchase_price" type="number" step="0.01" min="0" class="min-w-[6.5rem]" /></td>
                                        <td class="px-2 py-2"><TextInput v-model="v.mrp" type="number" step="0.01" min="0" class="min-w-[6.5rem]" /></td>
                                        <td class="px-2 py-2"><TextInput v-model="v.selling_price" type="number" step="0.01" min="0" class="min-w-[6.5rem]" /></td>
                                        <td class="px-2 py-2"><TextInput v-model="v.wholesale_price" type="number" step="0.01" min="0" class="min-w-[6.5rem]" /></td>
                                        <td v-if="form.track_stock" class="px-2 py-2"><TextInput v-model="v.reorder_level" type="number" min="0" class="min-w-[5rem]" /></td>
                                        <td v-if="!editing && form.track_stock" class="px-2 py-2">
                                            <TextInput v-model="v.opening_stock" type="number" :step="qtyStep" min="0" class="min-w-[5rem]" />
                                        </td>
                                        <td class="py-2 pl-2">
                                            <button
                                                type="button"
                                                class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-red-600"
                                                title="Don't stock this combination"
                                                @click="excludeRow(v)"
                                            >
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <button v-if="excluded.size" type="button" class="mt-2 text-sm text-indigo-600 hover:text-indigo-800" @click="excluded = new Set()">
                            Restore {{ excluded.size }} removed combination{{ excluded.size > 1 ? 's' : '' }}
                        </button>
                    </div>
                    <p v-else-if="selection.length" class="text-sm text-gray-500">Select at least one value for each attribute.</p>
                    <p v-else class="text-sm text-gray-500">Choose what this product varies by — e.g. Colour and Shirt Size.</p>

                    <p v-if="removedExisting.length" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                        Saving will remove {{ removedExisting.length }} existing variant{{ removedExisting.length > 1 ? 's' : '' }}:
                        {{ removedExisting.map((v) => v.name ?? labelOf(v.options)).join(', ') }}. Their stock history is kept.
                    </p>
                    <p v-if="editing && form.track_stock" class="text-xs text-gray-500">
                        To change stock of existing variants use a stock adjustment. New variants start at zero stock.
                    </p>
                </div>

                <p v-if="!isVariable && single?.selling_price" class="mt-4 text-xs text-gray-500">
                    Customer pays {{ money(single.selling_price) }}{{ form.price_includes_tax ? ' (GST included)' : ' + GST' }}.
                </p>
            </Card>

            <div class="flex justify-end gap-3">
                <Link
                    :href="editing ? route('products.show', product.id) : route('products.index')"
                    class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100"
                    >Cancel</Link
                >
                <PrimaryButton :disabled="form.processing">{{ editing ? 'Save changes' : 'Create product' }}</PrimaryButton>
            </div>
        </form>
    </AppLayout>
</template>
