<script setup>
/**
 * Period picker for lists: a preset menu (today, this month, last FY…) plus from/to dates.
 *   <DateRangeFilter v-model:from="filters.from" v-model:to="filters.to" />
 */
import { datePresets, matchPreset } from '@/utils/dateRanges';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const from = defineModel('from', { type: String, default: '' });
const to = defineModel('to', { type: String, default: '' });
const props = defineProps({ allowAny: { type: Boolean, default: true } });

const presets = computed(() => datePresets(usePage().props.app?.fy_start ?? '04-01'));
const preset = ref(matchPreset(presets.value, from.value, to.value));
const options = computed(() => [
    ...(props.allowAny ? [{ value: 'any', label: 'Any date' }] : []),
    ...Object.entries(presets.value).map(([value, [label]]) => ({ value, label })),
    { value: 'custom', label: 'Custom…' },
]);

const choose = (value) => {
    preset.value = value;
    if (value === 'any') {
        from.value = '';
        to.value = '';
    } else if (value !== 'custom') {
        [, from.value, to.value] = presets.value[value];
    }
};
// keep the menu in step when the dates change from outside (restored filters, back button)
watch([from, to], ([f, t]) => {
    if (preset.value !== 'custom') preset.value = matchPreset(presets.value, f, t);
});
</script>

<template>
    <div class="grid min-w-0 grid-cols-1 gap-2" :class="preset === 'custom' ? 'sm:grid-cols-3' : ''">
        <select
            :value="preset"
            aria-label="Period"
            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            @change="choose($event.target.value)"
        >
            <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <template v-if="preset === 'custom'">
            <input v-model="from" type="date" aria-label="From" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <input v-model="to" type="date" :min="from" aria-label="To" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
        </template>
    </div>
</template>
