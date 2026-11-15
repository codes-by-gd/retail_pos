<script setup>
/**
 * Pick a customer or supplier by name / phone / code.
 *   <ContactPicker v-model="supplier" kind="supplier" />
 * The model is the contact object returned by contacts.search (or null).
 */
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { ref } from 'vue';
import { money } from '@/utils/format';

const model = defineModel({ type: Object, default: null });
const props = defineProps({
    kind: { type: String, default: 'customer' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const term = ref('');
const results = ref([]);
const open = ref(false);
const active = ref(0);

const search = useDebounceFn(async () => {
    if (!term.value.trim()) return (results.value = []);
    const { data } = await axios.get(route('contacts.search'), { params: { q: term.value, kind: props.kind } });
    results.value = data.data.filter((c) => !c.is_walk_in);
    active.value = 0;
    open.value = true;
}, 200);

const choose = (c) => {
    model.value = c;
    term.value = '';
    results.value = [];
    open.value = false;
};
const onBlur = () => setTimeout(() => (open.value = false), 150);
</script>

<template>
    <div v-if="model" class="flex items-start justify-between gap-3 rounded-md border border-gray-300 bg-gray-50 px-3 py-2">
        <div class="min-w-0 text-sm">
            <p class="truncate font-medium text-gray-900">{{ model.company_name || model.name }}</p>
            <p class="truncate text-xs text-gray-500">
                {{ model.code }}<span v-if="model.phone"> · {{ model.phone }}</span><span v-if="model.gstin"> · {{ model.gstin }}</span>
                <span v-if="model.balance > 0" class="text-amber-700"> · owes us {{ money(model.balance) }}</span>
                <span v-else-if="model.balance < 0" class="text-indigo-700"> · we owe {{ money(-model.balance) }}</span>
            </p>
        </div>
        <button v-if="!disabled" type="button" class="shrink-0 text-gray-400 hover:text-red-600" title="Change" @click="model = null">
            <XMarkIcon class="h-5 w-5" />
        </button>
    </div>
    <div v-else class="relative">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
        <input
            v-model="term"
            type="text"
            autocomplete="off"
            :placeholder="placeholder || (kind === 'supplier' ? 'Search supplier name, phone or code…' : 'Search customer name, mobile or code…')"
            class="block w-full rounded-md border-gray-300 pl-9 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            @input="search"
            @keydown.down.prevent="active = Math.min(active + 1, results.length - 1)"
            @keydown.up.prevent="active = Math.max(active - 1, 0)"
            @keydown.enter.prevent="results[active] && choose(results[active])"
            @blur="onBlur"
        />
        <ul v-if="open" class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg">
            <li v-if="!results.length" class="px-3 py-2 text-gray-500">No {{ kind }} found.</li>
            <li
                v-for="(c, i) in results"
                :key="c.id"
                class="cursor-pointer px-3 py-2"
                :class="i === active ? 'bg-indigo-50' : 'hover:bg-gray-50'"
                @mousedown.prevent="choose(c)"
                @mouseenter="active = i"
            >
                <p class="font-medium text-gray-900">{{ c.company_name || c.name }}</p>
                <p class="text-xs text-gray-500">{{ c.code }}<span v-if="c.phone"> · {{ c.phone }}</span></p>
            </li>
        </ul>
    </div>
</template>
