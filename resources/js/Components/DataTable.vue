<script setup>
/**
 * Simple table. Customise a cell with a slot named after the column key:
 *   <DataTable :columns="[{ key: 'name', label: 'Name' }]" :rows="users.data">
 *     <template #name="{ row }">{{ row.name }}</template>
 *   </DataTable>
 */
defineProps({
    columns: { type: Array, required: true }, // [{ key, label, class?, align? }]
    rows: { type: Array, required: true },
    rowKey: { type: String, default: 'id' },
    empty: { type: String, default: 'No records found.' },
});
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
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
</template>
