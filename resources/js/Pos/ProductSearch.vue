<script setup>
/**
 * Scan or search. A scan (exact barcode/SKU + Enter) adds the item straight away.
 * Search results are grouped by product with a chip per size/colour showing stock.
 */
import { money, qty } from '@/utils/format';
import { MagnifyingGlassIcon, QrCodeIcon } from '@heroicons/vue/24/outline';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { computed, ref } from 'vue';
import { beep } from './helpers';

const props = defineProps({ beepOnScan: { type: Boolean, default: true } });
const emit = defineEmits(['add']);

const term = ref('');
const results = ref([]);
const active = ref(0);
const loading = ref(false);
const message = ref('');
const input = ref(null);
let requestId = 0;

const search = async (q) => {
    const id = ++requestId;
    if (!q.trim()) {
        results.value = [];
        return [];
    }
    loading.value = true;
    try {
        const { data } = await axios.get(route('variants.search'), { params: { q, limit: 40 } });
        if (id !== requestId) return results.value;
        results.value = data.data;
        active.value = 0;
        message.value = data.data.length ? '' : `Nothing found for “${q}”.`;
        return data.data;
    } finally {
        if (id === requestId) loading.value = false;
    }
};
const debounced = useDebounceFn(search, 180);

const groups = computed(() => {
    const map = new Map();
    results.value.forEach((v, index) => {
        if (!map.has(v.product_id)) map.set(v.product_id, { product_id: v.product_id, name: v.product_name, article_no: v.article_no, brand: v.brand, variants: [] });
        map.get(v.product_id).variants.push({ ...v, index });
    });
    return [...map.values()];
});

const pick = (variant) => {
    emit('add', variant);
    term.value = '';
    results.value = [];
    message.value = '';
    input.value?.focus();
};

const onEnter = async () => {
    const q = term.value.trim();
    if (!q) return;
    const list = results.value.length ? results.value : await search(q);
    const exact = list.find((v) => v.sku.toLowerCase() === q.toLowerCase() || v.barcodes.includes(q));
    if (exact) {
        if (props.beepOnScan) beep(true);
        return pick(exact);
    }
    if (list.length === 1 || (list[active.value] && results.value.length)) return pick(list[active.value] ?? list[0]);
    if (props.beepOnScan) beep(false);
    message.value = `No item with barcode / SKU “${q}”.`;
};

const move = (step) => {
    if (!results.value.length) return;
    active.value = (active.value + step + results.value.length) % results.value.length;
};

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-gray-400" />
            <input
                ref="input"
                v-model="term"
                type="text"
                autocomplete="off"
                placeholder="Scan tag or search name, article no., SKU…  (F2)"
                class="block w-full rounded-lg border-gray-300 py-3.5 pl-12 pr-24 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                @input="debounced(term)"
                @keydown.enter.prevent="onEnter"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.esc="(term = ''), (results = []), (message = '')"
            />
            <span class="absolute right-4 top-1/2 flex -translate-y-1/2 items-center gap-1 text-xs text-gray-400">
                <template v-if="loading">Searching…</template>
                <template v-else><QrCodeIcon class="h-4 w-4" /> Scan ready</template>
            </span>
        </div>
        <p v-if="message" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ message }}</p>

        <div class="mt-3 min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
            <div v-for="g in groups" :key="g.product_id" class="rounded-lg border border-gray-200 bg-white p-3">
                <div class="flex items-baseline justify-between gap-2">
                    <p class="font-medium text-gray-900">{{ g.name }}</p>
                    <p class="shrink-0 text-xs text-gray-500">
                        <span v-if="g.brand">{{ g.brand }}</span><span v-if="g.article_no"> · Art. {{ g.article_no }}</span>
                    </p>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="v in g.variants"
                        :key="v.id"
                        type="button"
                        class="rounded-md border px-2.5 py-1.5 text-left text-sm transition"
                        :class="[
                            v.index === active ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-gray-200 hover:border-indigo-400',
                            v.track_stock && v.stock <= 0 ? 'bg-red-50' : 'bg-white',
                        ]"
                        @click="pick(v)"
                        @mouseenter="active = v.index"
                    >
                        <span class="block font-medium text-gray-900">{{ v.variant_name ?? 'Add' }}</span>
                        <span class="block text-xs text-gray-500">
                            {{ money(v.selling_price) }}
                            <template v-if="v.track_stock">
                                · <span :class="v.stock <= 0 ? 'font-medium text-red-600' : ''">{{ qty(v.stock) }} left</span>
                            </template>
                        </span>
                    </button>
                </div>
            </div>
            <div v-if="!groups.length && !message" class="flex h-full flex-col items-center justify-center text-center text-sm text-gray-400">
                <QrCodeIcon class="mb-2 h-12 w-12" />
                <p>Scan a barcode tag, or type to search.</p>
                <p class="mt-4 text-xs leading-6">
                    <kbd class="kbd">F2</kbd> search · <kbd class="kbd">F4</kbd> customer · <kbd class="kbd">F6</kbd> discount ·
                    <kbd class="kbd">F8</kbd> hold · <kbd class="kbd">F9</kbd> pay
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.kbd {
    @apply rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 font-mono text-[11px] text-gray-600;
}
</style>
