<script setup>
/**
 * Single-series vertical bar chart (Chart.js). One series → no legend; the card title names it.
 *   <BarChart :labels="['01 Nov', …]" :values="[1200, …]" label="Net sales" :titles="['Sun, 01 Nov 2026', …]" />
 */
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip } from 'chart.js';
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { money, number } from '@/utils/format';

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip);

const props = defineProps({
    labels: { type: Array, required: true },
    values: { type: Array, required: true },
    label: { type: String, default: '' },
    /** Longer label per bar for the tooltip title */
    titles: { type: Array, default: null },
    /** Extra tooltip line per bar, e.g. "12 bills" */
    details: { type: Array, default: null },
    format: { type: String, default: 'money' }, // money | number
    height: { type: Number, default: 240 },
});

const SERIES = '#2a78d6';
const SERIES_HOVER = '#1f63b5';
const fmt = (v) => (props.format === 'money' ? money(v) : number(v, 2));
const compact = (v) => {
    const a = Math.abs(v);
    if (props.format !== 'money') return number(v);
    if (a >= 1e7) return `₹${number(v / 1e7, 1)}Cr`;
    if (a >= 1e5) return `₹${number(v / 1e5, 1)}L`;
    if (a >= 1e3) return `₹${number(v / 1e3, 1)}k`;
    return `₹${number(v)}`;
};

const data = computed(() => ({
    labels: props.labels,
    datasets: [{
        label: props.label,
        data: props.values,
        backgroundColor: SERIES,
        hoverBackgroundColor: SERIES_HOVER,
        borderRadius: { topLeft: 4, topRight: 4 },
        borderSkipped: 'start',
        maxBarThickness: 28,
        categoryPercentage: 0.8,
        barPercentage: 0.9,
    }],
}));

const options = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,   // draw final bars immediately (also in print / background tabs)
    interaction: { mode: 'index', intersect: false },   // hover anywhere in the column
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#111827',
            padding: 10,
            displayColors: false,
            callbacks: {
                title: (items) => (props.titles ? props.titles[items[0].dataIndex] : items[0].label),
                label: (item) => `${props.label ? props.label + ': ' : ''}${fmt(item.raw)}`,
                afterLabel: (item) => (props.details ? props.details[item.dataIndex] : ''),
            },
        },
    },
    scales: {
        x: { grid: { display: false }, border: { color: '#e5e7eb' }, ticks: { color: '#6b7280', font: { size: 11 }, maxRotation: 0, autoSkipPadding: 12 } },
        y: { beginAtZero: true, grid: { color: '#f3f4f6' }, border: { display: false }, ticks: { color: '#6b7280', font: { size: 11 }, maxTicksLimit: 5, callback: compact } },
    },
}));
</script>

<template>
    <div :style="{ height: `${height}px` }" role="img" :aria-label="label">
        <Bar :data="data" :options="options" />
    </div>
</template>
