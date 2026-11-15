<script setup>
/**
 * Simple table. Customise a cell with a slot named after the column key:
 *   <DataTable :columns="[{ key: 'name', label: 'Name' }]" :rows="users.data">
 *     <template #name="{ row }">{{ row.name }}</template>
 *   </DataTable>
 * On phones each row becomes a card (first column = title, others = label: value, `actions` bottom-right),
 * using the same slots. Columns can opt out of the card with `mobile: false`.
 */
import { computed } from 'vue';

const props = defineProps({
    columns: { type: Array, required: true }, // [{ key, label, class?, align?, mobile? }]
    rows: { type: Array, required: true },
    rowKey: { type: String, default: 'id' },
    empty: { type: String, default: 'No records found.' },
    cards: { type: Boolean, default: true }, // phone card layout
});

const titleCol = computed(() => props.columns[0]);
const detailCols = computed(() => props.columns.slice(1).filter((c) => c.key !== 'actions' && c.mobile !== false));
const actionsCol = computed(() => props.columns.find((c) => c.key === 'actions'));
</script>

<template>
    <div>
        <!-- Phones: cards -->
        <ul v-if="cards" class="divide-y divide-gray-100 sm:hidden">
            <li v-for="row in rows" :key="row[rowKey]" class="px-4 py-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1 text-sm text-gray-900">
                        <slot :name="titleCol.key" :row="row">{{ row[titleCol.key] ?? '—' }}</slot>
                    </div>
                    <div v-if="actionsCol" class="-mr-1 shrink-0"><slot name="actions" :row="row" /></div>
                </div>
                <dl v-if="detailCols.length" class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                    <div v-for="col in detailCols" :key="col.key" class="min-w-0" :class="col.align === 'right' ? 'text-right' : ''">
                        <dt class="text-[11px] uppercase tracking-wide text-gray-400">{{ col.label }}</dt>
                        <dd class="min-w-0 break-words text-gray-700"><slot :name="col.key" :row="row">{{ row[col.key] ?? '—' }}</slot></dd>
                    </div>
                </dl>
            </li>
            <li v-if="!rows.length" class="px-4 py-10 text-center text-sm text-gray-500">{{ empty }}</li>
        </ul>

        <!-- Tablets & desktop: table -->
        <div class="overflow-x-auto" :class="cards ? 'hidden sm:block' : ''">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            scope="col"
                            class="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500"
                            :class="[col.align === 'right' ? 'text-right' : 'text-left', col.class]"
                        >
                            {{ col.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-for="row in rows" :key="row[rowKey]" class="hover:bg-gray-50">
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-3 text-gray-700"
                            :class="[col.align === 'right' ? 'text-right' : '', col.class]"
                        >
                            <slot :name="col.key" :row="row">{{ row[col.key] ?? '—' }}</slot>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td :colspan="columns.length" class="px-4 py-10 text-center text-gray-500">{{ empty }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
