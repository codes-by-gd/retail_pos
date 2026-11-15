<script setup>
import { datePresets, matchPreset as matchDates } from '@/utils/dateRanges';
import BarChart from '@/Components/BarChart.vue';
import Card from '@/Components/Card.vue';
import ReportTable from '@/Components/ReportTable.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownTrayIcon, ArrowLeftIcon, DocumentArrowDownIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Object, required: true },
    branchLabel: { type: String, default: '' },
    periodLabel: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    gstins: { type: Array, default: () => [] },
    fyStart: { type: String, default: '04-01' },
    sections: { type: Array, required: true },
});

// ---- date presets (shared with lists) ----
const presets = computed(() => datePresets(props.fyStart));
const presetOptions = computed(() => [
    ...Object.entries(presets.value).map(([value, [label]]) => ({ value, label })),
    { value: 'custom', label: 'Custom dates' },
]);
const matchPreset = (from, to) => { const m = matchDates(presets.value, from, to); return m === 'any' ? 'custom' : m; };

const state = reactive({
    from: props.filters.from,
    to: props.filters.to,
    branch: props.filters.branch,
    gstin: props.filters.gstin ?? '',
    ...props.filters.options,
});
const preset = ref(matchPreset(props.filters.from, props.filters.to));

watch(preset, (p) => {
    if (p !== 'custom') {
        state.from = presets.value[p][1];
        state.to = presets.value[p][2];
    }
});

const query = computed(() => {
    const q = {};
    if (props.report.usesDates) Object.assign(q, { from: state.from, to: state.to });
    if (props.report.scope === 'branch') q.branch = state.branch;
    if (props.report.scope === 'gstin') q.gstin = state.gstin;
    props.options.forEach((o) => (q[o.name] = state[o.name]));
    return q;
});

const loading = ref(false);
watch(query, (q) => {
    if (props.report.usesDates && (!q.from || !q.to || q.from > q.to)) return;
    router.get(route('reports.show', props.report.key), q, {
        preserveState: true, preserveScroll: true, replace: true,
        onStart: () => (loading.value = true), onFinish: () => (loading.value = false),
    });
});

const exportUrl = (format) => route('reports.export', { report: props.report.key, ...query.value, format });

// ---- chart over the first section ----
const chart = computed(() => {
    const c = props.report.chart;
    const s = props.sections[0];
    if (!c || !s || s.rows.length < 2) return null;
    return { labels: s.rows.map((r) => r[c.x]), values: s.rows.map((r) => r[c.y] ?? 0), label: c.label };
});
</script>

<template>
    <AppLayout :title="report.title">
        <div class="mb-4">
            <Link :href="route('reports.index')" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800"><ArrowLeftIcon class="h-4 w-4" /> Reports</Link>
        </div>
        <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">{{ report.title }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    <span v-if="report.usesDates">{{ periodLabel }}</span><span v-else>As of now</span>
                    <span v-if="report.scope"> · {{ report.scope === 'gstin' && filters.gstin ? `GSTIN ${filters.gstin} · ` : '' }}{{ branchLabel }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a :href="exportUrl('xlsx')"><SecondaryButton type="button"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</SecondaryButton></a>
                <a :href="exportUrl('csv')"><SecondaryButton type="button"><DocumentArrowDownIcon class="h-4 w-4" /> CSV</SecondaryButton></a>
                <a :href="exportUrl('pdf')" target="_blank" rel="noopener"><SecondaryButton type="button"><PrinterIcon class="h-4 w-4" /> PDF / Print</SecondaryButton></a>
            </div>
        </div>

        <Card :padded="false" class="mb-5">
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                <template v-if="report.usesDates">
                    <label class="block text-xs font-medium text-gray-600">Period
                        <SelectInput v-model="preset" :options="presetOptions" class="mt-1" />
                    </label>
                    <label class="block text-xs font-medium text-gray-600">From
                        <TextInput v-model="state.from" type="date" class="mt-1" @input="preset = 'custom'" />
                    </label>
                    <label class="block text-xs font-medium text-gray-600">To
                        <TextInput v-model="state.to" type="date" :min="state.from" class="mt-1" @input="preset = 'custom'" />
                    </label>
                </template>
                <label v-if="report.scope === 'branch' && branches.length > 1" class="block text-xs font-medium text-gray-600">Branch
                    <SelectInput v-model="state.branch" :options="branches" class="mt-1" />
                </label>
                <label v-if="report.scope === 'gstin'" class="block text-xs font-medium text-gray-600 sm:col-span-2">GST registration
                    <SelectInput v-model="state.gstin" :options="gstins" class="mt-1" />
                </label>
                <label v-for="o in options" :key="o.name" class="block text-xs font-medium text-gray-600">{{ o.label }}
                    <SelectInput v-model="state[o.name]" :options="o.options" class="mt-1" />
                </label>
            </div>
        </Card>

        <div class="space-y-5 transition-opacity" :class="loading ? 'opacity-60' : ''">
            <Card v-if="chart" :title="chart.label">
                <BarChart :labels="chart.labels" :values="chart.values" :label="chart.label" :height="220" />
            </Card>
            <Card v-for="(s, i) in sections" :key="i" :title="sections.length > 1 ? s.title : ''" :padded="false">
                <ReportTable :section="s" />
            </Card>
        </div>
    </AppLayout>
</template>
