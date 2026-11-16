<script setup>
/**
 * Search box for sellable items (variants). Works with barcode scanners:
 * a scan that matches exactly one barcode/SKU is selected immediately on Enter.
 *
 *   <VariantPicker @select="(item) => addLine(item)" />
 */
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { ref } from 'vue';
import { money, qty } from '@/utils/format';
import { play } from '@/utils/sounds';
import ScanButton from '@/Components/ScanButton.vue';

const props = defineProps({
    placeholder: { type: String, default: 'Scan barcode or search item name / SKU…' },
    autofocus: { type: Boolean, default: false },
    showStock: { type: Boolean, default: true },
    /** Camera button: "continuous" keeps scanning (receiving goods, counts), "single" closes after one item. */
    scanMode: { type: String, default: 'single' },
});
const emit = defineEmits(['select']);

const term = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
const active = ref(0);
const error = ref('');
const input = ref(null);
let requestId = 0;
let resultsFor = ''; // the text the current results belong to

const search = async (q) => {
    const id = ++requestId;
    if (!q.trim()) {
        results.value = [];
        open.value = false;
        return [];
    }
    loading.value = true;
    try {
        const { data } = await axios.get(route('variants.search'), { params: { q } });
        if (id !== requestId) return results.value; // a newer search is running
        results.value = data.data;
        resultsFor = q;
        active.value = 0;
        open.value = true;
        error.value = '';
        return data.data;
    } finally {
        if (id === requestId) loading.value = false;
    }
};
const debouncedSearch = useDebounceFn(search, 200);

const choose = (item, focus = true) => {
    emit('select', item);
    term.value = '';
    results.value = [];
    open.value = false;
    if (focus) input.value?.focus();
};

/* ---------- phone / tablet camera: exact barcode / SKU only ---------- */
const cameraFeedback = ref(null);
const onCameraCode = async (code) => {
    const list = await search(code);
    open.value = false;
    const exact = list.find((i) => i.sku === code || i.barcodes.includes(code));
    if (exact) {
        choose(exact, false);
        cameraFeedback.value = { ok: true, text: `Added: ${exact.name}` };
    } else {
        play('notfound');
        cameraFeedback.value = { ok: false, text: `No item with barcode “${code}”` };
    }
};

const onEnter = async () => {
    // Scanners type fast and press Enter — search immediately and take an exact match.
    const q = term.value.trim();
    if (!q) return;
    // never use the results of a half-typed search for the full code (typed fast + Enter)
    const list = open.value && results.value.length && resultsFor === q ? results.value : await search(q);
    const exact = list.find((i) => i.sku === q || i.barcodes.includes(q));
    if (exact) return choose(exact);
    if (list[active.value]) return choose(list[active.value]);
    error.value = `No item found for “${q}”.`;
};

const move = (step) => {
    if (!results.value.length) return;
    active.value = (active.value + step + results.value.length) % results.value.length;
};

const onBlur = () => setTimeout(() => (open.value = false), 150);

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <div class="flex gap-2">
        <div class="relative min-w-0 flex-1">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
        <input
            ref="input"
            v-model="term"
            type="text"
            :placeholder="placeholder"
            :autofocus="autofocus"
            autocomplete="off"
            class="block w-full rounded-md border-gray-300 py-2.5 pl-10 text-sm shadow-xs focus:border-indigo-500 focus:ring-indigo-500"
            @input="debouncedSearch(term)"
            @keydown.enter.prevent="onEnter"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.esc="open = false"
            @blur="onBlur"
            @focus="results.length && (open = true)"
        />
        <span v-if="loading" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">Searching…</span>
        </div>
        <ScanButton :mode="scanMode" :title="scanMode === 'continuous' ? 'Scan items with the camera' : 'Scan an item with the camera'" :feedback="cameraFeedback"
            @detected="onCameraCode" @open="cameraFeedback = null" />
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>

        <ul
            v-if="open"
            class="absolute z-30 mt-1 max-h-80 w-full overflow-y-auto rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg"
        >
            <li v-if="!results.length" class="px-4 py-3 text-gray-500">No items match.</li>
            <li
                v-for="(item, i) in results"
                :key="item.id"
                class="flex cursor-pointer items-center justify-between gap-4 px-4 py-2"
                :class="i === active ? 'bg-indigo-50' : 'hover:bg-gray-50'"
                @mousedown.prevent="choose(item)"
                @mouseenter="active = i"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium text-gray-900">{{ item.name }}</p>
                    <p class="truncate text-xs text-gray-500">{{ item.sku }}<span v-if="item.barcode"> · {{ item.barcode }}</span></p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="font-medium text-gray-900">{{ money(item.selling_price) }}</p>
                    <p v-if="showStock && item.track_stock" class="text-xs" :class="item.stock > 0 ? 'text-gray-500' : 'text-red-600'">
                        Stock: {{ qty(item.stock) }} {{ item.unit }}
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
