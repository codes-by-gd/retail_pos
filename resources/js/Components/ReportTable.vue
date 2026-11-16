<script setup>
/** Renders one report section (columns typed money|qty|int|percent|date|datetime|text) with totals; click a heading to sort. */
import { computed, ref } from 'vue';
import { date, dateTime, money, number, qty } from '@/utils/format';

const props = defineProps({
    section: { type: Object, required: true }, // { title, columns, rows, totals, note, count, truncated }
});

const NUMERIC = ['money', 'qty', 'int', 'percent'];
const sortKey = ref(null);
const sortDir = ref(1);

const sort = (col) => {
    if (sortKey.value === col.key) {
        sortDir.value = -sortDir.value;
    } else {
        sortKey.value = col.key;
        sortDir.value = NUMERIC.includes(col.type) ? -1 : 1;
    }
};

const rows = computed(() => {
    if (!sortKey.value) return props.section.rows;
    const k = sortKey.value;
    return [...props.section.rows].sort((a, b) => {
        const x = a[k];
        const y = b[k];
        if (x === y) return 0;
        if (x === null || x === undefined) return 1;
        if (y === null || y === undefined) return -1;
        return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'en-IN', { numeric: true })) * sortDir.value;
    });
});

const show = (col, v) => {
    if (v === null || v === undefined || v === '') return '—';
    switch (col.type) {
        case 'money': return money(v);
        case 'qty': return qty(v);
        case 'int': return number(v);
        case 'percent': return `${number(v, 1)}%`;
        case 'date': return date(v);
        case 'datetime': return dateTime(v);
        default: return v;
    }
};
const negative = (col, v) => NUMERIC.includes(col.type) && Number(v) < 0;
const hasTotals = computed(() => props.section.rows.length && Object.keys(props.section.totals ?? {}).length);
</script>

<template>
    <div>
        <div class="overflow-auto" :class="section.rows.length > 15 ? 'max-h-[75vh]' : ''">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="sticky top-0 z-[1] bg-gray-50 shadow-[0_1px_0_var(--c-gray-200)]">
                    <tr>
                        <th
                            v-for="col in section.columns"
                            :key="col.key"
                            class="cursor-pointer select-none whitespace-nowrap px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-800"
                            :class="NUMERIC.includes(col.type) ? 'text-right' : 'text-left'"
                            :aria-sort="sortKey === col.key ? (sortDir > 0 ? 'ascending' : 'descending') : 'none'"
                            @click="sort(col)"
                        >
                            {{ col.label }}<span v-if="sortKey === col.key" class="ml-0.5">{{ sortDir > 0 ? '▲' : '▼' }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-for="(row, i) in rows" :key="i" class="hover:bg-gray-50" :class="{ 'bg-gray-50 font-semibold text-gray-900': ['total', 'head'].includes(row.kind), 'font-medium': row.kind === 'subtotal' }">
                        <td
                            v-for="(col, ci) in section.columns"
                            :key="col.key"
                            class="px-3 py-2"
                            :class="[
                                NUMERIC.includes(col.type) ? 'whitespace-nowrap text-right tabular-nums' : '',
                                ci === 0 ? 'font-medium text-gray-900' : 'text-gray-700',
                                ci === 0 && String(row[col.key] ?? '').startsWith('   ') ? 'pl-8' : '',
                                negative(col, row[col.key]) ? '!text-red-700' : '',
                                ['date', 'datetime'].includes(col.type) || (ci === 0 && String(row[col.key] ?? '').length <= 16) ? 'whitespace-nowrap' : '',
                            ]"
                        >{{ row.kind === 'head' && row[col.key] == null ? '' : show(col, row[col.key]) }}</td>
                    </tr>
                    <tr v-if="!section.rows.length">
                        <td :colspan="section.columns.length" class="px-3 py-8 text-center text-gray-500">Nothing for these filters.</td>
                    </tr>
                </tbody>
                <tfoot v-if="hasTotals" class="sticky bottom-0 border-t-2 border-gray-200 bg-gray-50 font-semibold text-gray-900 shadow-[0_-1px_0_var(--c-gray-200)]">
                    <tr>
                        <td
                            v-for="(col, ci) in section.columns"
                            :key="col.key"
                            class="px-3 py-2.5"
                            :class="NUMERIC.includes(col.type) ? 'whitespace-nowrap text-right tabular-nums' : ''"
                        >{{ col.key in section.totals ? show(col, section.totals[col.key]) : ci === 0 ? 'Total' : '' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p v-if="section.truncated" class="border-t border-amber-100 bg-amber-50 px-4 py-2 text-xs text-amber-800">
            Showing the first {{ section.rows.length }} of {{ section.count }} rows (totals cover all of them). Export to Excel for the full list.
        </p>
        <p v-if="section.note" class="px-4 py-3 text-xs text-gray-500">{{ section.note }}</p>
    </div>
</template>
