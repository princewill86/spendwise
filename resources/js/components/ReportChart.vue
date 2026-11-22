<script setup lang="ts">
import { ArcElement, CategoryScale, Chart, DoughnutController, LineController, LineElement, LinearScale, PointElement } from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

Chart.register(ArcElement, CategoryScale, DoughnutController, LineController, LineElement, LinearScale, PointElement);

const props = defineProps<{ labels: string[]; series: { label: string; values: (number | null)[]; color: string }[]; kind: 'line' | 'doughnut'; wholeNumbers?: boolean }>();
const canvas = ref<HTMLCanvasElement>();
let chart: Chart | undefined;
let observer: MutationObserver | undefined;
const media = window.matchMedia('(prefers-color-scheme: dark)');

function render() {
    if (!canvas.value) return;
    chart?.destroy();
    const tokens = getComputedStyle(document.documentElement);
    const muted = tokens.getPropertyValue('--muted').trim();
    const line = tokens.getPropertyValue('--line').trim();
    const common = { maintainAspectRatio: false as const, animation: false as const, events: [] };
    if (props.kind === 'doughnut') {
        chart = new Chart(canvas.value, {
            type: 'doughnut',
            data: {
                labels: props.labels,
                datasets: [{
                    data: props.series[0]?.values.map(value => value ?? 0) ?? [],
                    backgroundColor: ['#397c66', '#8871a3', '#ae596f', '#bd8b4f', '#3e7295', '#81944a', '#a75994', '#58716b'],
                    borderColor: tokens.getPropertyValue('--surface').trim(), borderWidth: 3,
                }],
            },
            options: { ...common, cutout: '66%' },
        });
        return;
    }
    chart = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: props.labels,
            datasets: props.series.map(item => ({ label: item.label, data: item.values, borderColor: item.color, backgroundColor: item.color, tension: .22, pointRadius: 3, borderWidth: 2 })),
        },
        options: { ...common, scales: { x: { ticks: { color: muted, maxTicksLimit: 8 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: muted, precision: props.wholeNumbers ? 0 : undefined }, grid: { color: line } } } },
    });
}

onMounted(() => {
    render();
    observer = new MutationObserver(render);
    observer.observe(document.documentElement, { attributeFilter: ['data-theme'] });
    media.addEventListener('change', render);
});
watch(() => [props.labels, props.series, props.kind], render);
onBeforeUnmount(() => {
    observer?.disconnect();
    media.removeEventListener('change', render);
    chart?.destroy();
});
</script>
<template><div class="report-chart" :class="kind"><canvas ref="canvas" aria-hidden="true"></canvas></div></template>
