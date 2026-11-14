<script setup>
import BarcodeSvg from '@/Components/BarcodeSvg.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import VariantPicker from '@/Components/VariantPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money } from '@/utils/format';
import { PrinterIcon, TrashIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    preselected: { type: Array, default: () => [] },
    businessName: { type: String, default: '' },
});

/**
 * Label stock presets. Sizes in mm. "sheet" = A4 page with a grid; "roll" = one label per page (thermal label printer).
 */
const presets = {
    a4_65: { label: 'A4 sheet · 65 labels (38.1 × 21.2 mm)', type: 'sheet', w: 38.1, h: 21.2, cols: 5, rows: 13, top: 10.7, left: 4.7, gapX: 2.5, gapY: 0 },
    a4_40: { label: 'A4 sheet · 40 labels (48.5 × 25.4 mm)', type: 'sheet', w: 48.5, h: 25.4, cols: 4, rows: 10, top: 21.5, left: 8, gapX: 0, gapY: 0 },
    a4_24: { label: 'A4 sheet · 24 labels (64 × 34 mm)', type: 'sheet', w: 64, h: 34, cols: 3, rows: 8, top: 12.5, left: 7, gapX: 2, gapY: 0 },
    roll_50x25: { label: 'Thermal roll · 50 × 25 mm', type: 'roll', w: 50, h: 25 },
    roll_38x25: { label: 'Thermal roll · 38 × 25 mm', type: 'roll', w: 38, h: 25 },
    roll_100x50: { label: 'Thermal roll · 100 × 50 mm', type: 'roll', w: 100, h: 50 },
    tag_50x75: { label: 'Garment hang tag · 50 × 75 mm', type: 'roll', w: 50, h: 75 },
};

const STORAGE_KEY = 'pos.labelSettings';
const saved = (() => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    } catch {
        return {};
    }
})();

const settings = reactive({
    preset: 'roll_50x25',
    showBusiness: true,
    showBrand: true,
    showName: true,
    showVariant: true,
    showArticle: true,
    showPrice: true,
    showMrp: true,
    showSku: false,
    skip: 0,
    ...saved,
});
watch(settings, (s) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...s, skip: 0 }));
    } catch {
        /* storage unavailable — settings just won't be remembered */
    }
});

const preset = computed(() => presets[settings.preset]);
const lines = ref([]); // { id, name, sku, barcode, selling_price, mrp, copies }

const add = (item, copies = 1) => {
    const existing = lines.value.find((l) => l.id === item.id);
    if (existing) {
        existing.copies = Number(existing.copies) + 1;
        return;
    }
    lines.value.push({
        id: item.id,
        name: item.name,
        product_name: item.product_name,
        variant_name: item.variant_name,
        brand: item.brand,
        article_no: item.article_no,
        sku: item.sku,
        barcode: item.barcode || item.sku,
        selling_price: item.selling_price,
        mrp: item.mrp,
        copies,
        stock: item.stock,
    });
};

onMounted(async () => {
    if (!props.preselected.length) return;
    const { data } = await axios.get(route('variants.search'), { params: { ids: props.preselected } });
    data.data.forEach((item) => add(item));
});

const setCopiesToStock = () => lines.value.forEach((l) => (l.copies = Math.max(1, Math.floor(l.stock || 0))));

// Flatten into individual labels, with blank positions first for part-used sheets.
const labels = computed(() => {
    const out = [];
    if (preset.value.type === 'sheet') for (let i = 0; i < Number(settings.skip || 0); i++) out.push(null);
    lines.value.forEach((l) => {
        for (let i = 0; i < Math.min(Number(l.copies) || 0, 1000); i++) out.push(l);
    });
    return out;
});
const labelCount = computed(() => labels.value.filter(Boolean).length);

const pages = computed(() => {
    const per = preset.value.type === 'sheet' ? preset.value.cols * preset.value.rows : 1;
    const result = [];
    for (let i = 0; i < labels.value.length; i += per) result.push(labels.value.slice(i, i + per));
    return result;
});

// Barcode height scales with label height.
const barcodeHeight = computed(() => Math.max(14, Math.round(preset.value.h * 1.2)));

// Inject @page rules for printing (page size differs per preset).
let styleEl;
const pageCss = computed(() => {
    const p = preset.value;
    return p.type === 'sheet' ? '@page { size: A4; margin: 0; }' : `@page { size: ${p.w}mm ${p.h}mm; margin: 0; }`;
});
onMounted(() => {
    styleEl = document.createElement('style');
    document.head.appendChild(styleEl);
    watch(pageCss, (css) => (styleEl.textContent = `@media print { ${css} }`), { immediate: true });
});
onUnmounted(() => styleEl?.remove());

const print = () => window.print();
</script>

<template>
    <AppLayout title="Barcode labels">
        <div class="no-print">
            <PageHeader title="Barcode labels" description="Pick items and copies, choose your label stock, then print.">
                <template #actions>
                    <PrimaryButton type="button" :disabled="!labelCount" @click="print">
                        <PrinterIcon class="h-4 w-4" /> Print {{ labelCount }} label{{ labelCount === 1 ? '' : 's' }}
                    </PrimaryButton>
                </template>
            </PageHeader>

            <div class="grid gap-6 xl:grid-cols-3">
                <Card title="Items" class="xl:col-span-2">
                    <VariantPicker :show-stock="true" autofocus @select="add" />
                    <table v-if="lines.length" class="mt-4 min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="py-2">Item</th>
                                <th class="py-2 text-right">Price</th>
                                <th class="px-3 py-2">Copies</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in lines" :key="l.id" class="border-t border-gray-100">
                                <td class="py-2">
                                    <p class="font-medium text-gray-900">{{ l.name }}</p>
                                    <p class="font-mono text-xs text-gray-500">{{ l.barcode }}</p>
                                </td>
                                <td class="py-2 text-right">{{ money(l.selling_price) }}</td>
                                <td class="px-3 py-2"><TextInput v-model="l.copies" type="number" min="0" max="1000" class="!w-24" /></td>
                                <td class="py-2 text-right">
                                    <button class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-red-600" @click="lines.splice(i, 1)">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="lines.length" class="mt-3 flex gap-4 text-sm">
                        <button class="font-medium text-indigo-600 hover:text-indigo-800" @click="setCopiesToStock">Copies = stock on hand</button>
                        <button class="text-gray-500 hover:text-gray-700" @click="lines = []">Clear all</button>
                    </div>
                    <p v-else class="mt-4 text-sm text-gray-500">Scan or search items, or select products on the Products page and choose “Print labels”.</p>
                </Card>

                <Card title="Label settings">
                    <div class="space-y-4">
                        <FormField label="Label stock">
                            <SelectInput v-model="settings.preset" :options="Object.entries(presets).map(([value, p]) => ({ value, label: p.label }))" />
                        </FormField>
                        <FormField v-if="preset.type === 'sheet'" label="Skip labels at start" hint="To reuse a partly used sheet.">
                            <TextInput v-model="settings.skip" type="number" min="0" :max="preset.cols * preset.rows - 1" />
                        </FormField>
                        <fieldset class="space-y-2 text-sm">
                            <legend class="mb-1 font-medium text-gray-700">Show on label</legend>
                            <label v-for="opt in [
                                ['showBusiness', 'Business name'],
                                ['showBrand', 'Brand'],
                                ['showName', 'Product name'],
                                ['showVariant', 'Size / colour'],
                                ['showArticle', 'Article no.'],
                                ['showPrice', 'Selling price'],
                                ['showMrp', 'MRP'],
                                ['showSku', 'SKU'],
                            ]" :key="opt[0]" class="flex items-center gap-2">
                                <input v-model="settings[opt[0]]" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                {{ opt[1] }}
                            </label>
                        </fieldset>
                        <p class="text-xs text-gray-500">
                            In the print dialog set margins to “None” and scale to 100%. Settings are remembered on this computer.
                        </p>
                    </div>
                </Card>
            </div>

            <h3 v-if="labelCount" class="mb-2 mt-8 text-sm font-semibold text-gray-700">Preview</h3>
        </div>

        <!-- Printable area (also the on-screen preview) -->
        <div v-if="labelCount" class="print-area overflow-x-auto">
            <div
                v-for="(page, pi) in pages"
                :key="pi"
                class="label-page mb-6 bg-white shadow-sm ring-1 ring-gray-200"
                :style="
                    preset.type === 'sheet'
                        ? {
                              width: '210mm',
                              height: '297mm',
                              paddingTop: `${preset.top}mm`,
                              paddingLeft: `${preset.left}mm`,
                              display: 'grid',
                              gridTemplateColumns: `repeat(${preset.cols}, ${preset.w}mm)`,
                              gridAutoRows: `${preset.h}mm`,
                              columnGap: `${preset.gapX}mm`,
                              rowGap: `${preset.gapY}mm`,
                              alignContent: 'start',
                          }
                        : { width: `${preset.w}mm`, height: `${preset.h}mm` }
                "
            >
                <div
                    v-for="(l, li) in page"
                    :key="li"
                    class="label flex flex-col items-center justify-center overflow-hidden px-[1.5mm] text-center leading-tight text-black"
                    :style="{ width: `${preset.w}mm`, height: `${preset.h}mm` }"
                >
                    <template v-if="l">
                        <p v-if="settings.showBusiness" class="w-full truncate text-[6pt] font-semibold uppercase">{{ businessName }}</p>
                        <p v-if="settings.showBrand && l.brand" class="w-full truncate text-[7pt] font-bold">{{ l.brand }}</p>
                        <p v-if="settings.showName" class="w-full truncate text-[7pt]">{{ l.product_name }}</p>
                        <p v-if="settings.showVariant && l.variant_name" class="w-full truncate text-[8pt] font-bold">{{ l.variant_name }}</p>
                        <p v-if="settings.showArticle && l.article_no" class="w-full truncate font-mono text-[6pt]">Art. {{ l.article_no }}</p>
                        <BarcodeSvg :value="l.barcode" :height="barcodeHeight" :width="preset.w < 45 ? 1 : 1.4" :font-size="9" />
                        <p class="flex w-full items-baseline justify-center gap-2 text-[7pt]">
                            <span v-if="settings.showMrp && l.mrp" :class="settings.showPrice && l.mrp > l.selling_price ? 'line-through' : ''">MRP {{ money(l.mrp) }}</span>
                            <span v-if="settings.showPrice" class="text-[8pt] font-bold">{{ money(l.selling_price) }}</span>
                        </p>
                        <p v-if="settings.showSku" class="font-mono text-[6pt]">{{ l.sku }}</p>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .print-area,
    .print-area * {
        visibility: visible;
    }
    .print-area {
        position: absolute;
        left: 0;
        top: 0;
    }
    .label-page {
        margin: 0 !important;
        box-shadow: none !important;
        page-break-after: always;
        break-after: page;
    }
    .label-page:last-child {
        page-break-after: auto;
        break-after: auto;
    }
}
</style>
