<script setup lang="ts">
import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const props = defineProps<{ currency: string; income: string; expense: string }>();
const canvas = ref<HTMLCanvasElement>();
let chart: Chart<'bar'> | undefined;

function renderChart() {
    if (!canvas.value) return;
    chart?.destroy();
    const styles = getComputedStyle(document.documentElement);
    chart = new Chart(canvas.value, {
        type: 'bar',
        data: {
            labels: ['Income', 'Expenses'],
            datasets: [{
                // The chart uses numbers for display. Totals and labels come from the exact server calculation.
                data: [Number(props.income), Number(props.expense)],
                backgroundColor: ['#397c66', '#ac5873'],
                borderRadius: 4,
                maxBarThickness: 30,
            }],
        },
        options: {
            indexAxis: 'y',
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: { display: false, beginAtZero: true },
                y: { grid: { display: false }, ticks: { color: styles.getPropertyValue('--muted') } },
            },
            plugins: { tooltip: { enabled: false } },
        },
    });
}

let themeObserver: MutationObserver | undefined;
const themePreference = window.matchMedia('(prefers-color-scheme: dark)');
onMounted(() => {
    renderChart();
    themeObserver = new MutationObserver(renderChart);
    themeObserver.observe(document.documentElement, { attributeFilter: ['data-theme'] });
    themePreference.addEventListener('change', renderChart);
});
watch(() => [props.currency, props.income, props.expense], renderChart);
onBeforeUnmount(() => {
    themeObserver?.disconnect();
    themePreference.removeEventListener('change', renderChart);
    chart?.destroy();
});
</script>

<template>
    <div class="cash-flow-chart"><canvas ref="canvas" aria-hidden="true"></canvas></div>
</template>
