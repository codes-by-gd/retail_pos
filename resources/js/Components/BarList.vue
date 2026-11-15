<script setup>
/**
 * Ranked list with a thin bar per row — for "top N" and shares, where a label + number reads better than a chart.
 *   <BarList :items="[{ label: 'Cash', value: 1200, sub: '45%' }]" />
 */
import { computed } from 'vue';
import { money, number } from '@/utils/format';

const props = defineProps({
    items: { type: Array, required: true }, // { label, value, sub?, hint? }
    format: { type: String, default: 'money' },
    empty: { type: String, default: 'Nothing yet.' },
});

const max = computed(() => Math.max(...props.items.map((i) => Math.abs(i.value)), 0));
</script>

<template>
    <ul v-if="items.length" class="space-y-3">
        <li v-for="item in items" :key="item.label">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate text-gray-700" :title="item.label">
                    {{ item.label }}<span v-if="item.hint" class="ml-1 text-xs text-gray-400">{{ item.hint }}</span>
                </span>
                <span class="shrink-0 tabular-nums text-gray-900">
                    {{ format === 'money' ? money(item.value) : number(item.value, 3) }}
                    <span v-if="item.sub" class="ml-1 text-xs text-gray-500">{{ item.sub }}</span>
                </span>
            </div>
            <div class="mt-1 h-1.5 rounded-full bg-gray-100">
                <div class="h-1.5 rounded-full bg-[#2a78d6]" :style="{ width: `${max ? Math.max(2, (Math.abs(item.value) / max) * 100) : 0}%` }" />
            </div>
        </li>
    </ul>
    <p v-else class="py-6 text-center text-sm text-gray-500">{{ empty }}</p>
</template>
