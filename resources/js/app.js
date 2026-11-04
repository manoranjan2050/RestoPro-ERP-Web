import './bootstrap';
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;

const isDark = () => document.documentElement.classList.contains('dark');

const base = () => ({
    chart: {
        fontFamily: 'Plus Jakarta Sans, ui-sans-serif, system-ui, sans-serif',
        toolbar: { show: false },
        zoom: { enabled: false },
        background: 'transparent',
        animations: { speed: 500 },
    },
    theme: { mode: isDark() ? 'dark' : 'light' },
    grid: { borderColor: isDark() ? '#1e293b' : '#e2e8f0', strokeDashArray: 4 },
    dataLabels: { enabled: false },
    legend: { position: 'bottom', fontWeight: 500 },
    tooltip: { theme: isDark() ? 'dark' : 'light' },
});

const inr = (v) => '₹' + Math.round(v).toLocaleString('en-IN');

/**
 * Chart presets. Each takes the data object passed from Blade and returns ApexCharts options.
 */
const presets = {
    trend: (d) => ({
        chart: { type: 'area', height: 280 },
        series: [{ name: 'Sales', data: d.values }],
        xaxis: { categories: d.labels, tickAmount: Math.min(d.labels.length, 10), labels: { rotate: 0, hideOverlappingLabels: true } },
        yaxis: { labels: { formatter: (v) => inr(v) } },
        colors: ['#f97316'],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.02, stops: [0, 90, 100] } },
        tooltip: { y: { formatter: (v) => inr(v) } },
        markers: { size: d.values.length > 40 ? 0 : 3, strokeWidth: 0, hover: { size: 6 } },
    }),
    split: (d) => ({
        chart: { type: 'donut', height: 280 },
        series: d.values,
        labels: d.labels,
        colors: d.colors ?? ['#10b981', '#0ea5e9', '#f59e0b', '#8b5cf6', '#f43f5e', '#64748b'],
        stroke: { width: 0 },
        plotOptions: {
            pie: {
                donut: {
                    size: '70%',
                    labels: {
                        show: true,
                        value: { formatter: (v) => inr(v), fontWeight: 700 },
                        total: { show: true, label: 'Total', formatter: (w) => inr(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) },
                    },
                },
            },
        },
        tooltip: { y: { formatter: (v) => inr(v) } },
    }),
    bars: (d) => ({
        chart: { type: 'bar', height: 240 },
        series: [{ name: 'Sales', data: d.values }],
        xaxis: { categories: d.labels, labels: { rotate: 0, hideOverlappingLabels: true } },
        yaxis: { labels: { formatter: (v) => inr(v) } },
        colors: ['#f97316'],
        plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
        fill: { type: 'gradient', gradient: { shade: 'light', type: 'vertical', gradientToColors: ['#f43f5e'], stops: [0, 100] } },
        tooltip: { y: { formatter: (v) => inr(v) } },
    }),
};

const pct = (v) => (v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + v + '%');

Object.assign(presets, {
    // Sales / expenses / staff bars with a net-profit line.
    combo: (d) => ({
        chart: { type: 'line', height: 340, stacked: false },
        series: [
            { name: 'Sales', type: 'column', data: d.sales },
            { name: 'Expenses', type: 'column', data: d.expenses },
            { name: 'Staff cost', type: 'column', data: d.staff },
            { name: 'Net profit', type: 'line', data: d.net },
        ],
        xaxis: { categories: d.labels },
        yaxis: { labels: { formatter: (v) => inr(v) } },
        colors: ['#10b981', '#f43f5e', '#8b5cf6', '#f97316'],
        stroke: { width: [0, 0, 0, 3], curve: 'smooth' },
        plotOptions: { bar: { borderRadius: 4, columnWidth: '62%' } },
        markers: { size: [0, 0, 0, 4] },
        tooltip: { shared: true, intersect: false, y: { formatter: (v) => (v === null ? '—' : inr(v)) } },
    }),
    // This year vs last year sales lines.
    compare: (d) => ({
        chart: { type: 'line', height: 280 },
        series: [{ name: d.nowLabel, data: d.now }, { name: d.prevLabel, data: d.prev }],
        xaxis: { categories: d.labels },
        yaxis: { labels: { formatter: (v) => inr(v) } },
        colors: ['#f97316', '#94a3b8'],
        stroke: { width: [3, 2], curve: 'smooth', dashArray: [0, 5] },
        markers: { size: 3 },
        tooltip: { shared: true, intersect: false, y: { formatter: (v) => (v === null ? '—' : inr(v)) } },
    }),
    // Month-on-month growth %, green up / red down.
    growth: (d) => ({
        chart: { type: 'bar', height: 260 },
        series: [{ name: 'Growth', data: d.values }],
        xaxis: { categories: d.labels },
        yaxis: { labels: { formatter: (v) => Math.round(v) + '%' } },
        plotOptions: { bar: { borderRadius: 4, columnWidth: '55%', colors: { ranges: [{ from: -1e9, to: -0.0001, color: '#f43f5e' }, { from: 0, to: 1e9, color: '#10b981' }] } } },
        tooltip: { y: { formatter: pct } },
    }),
    // Running total (cumulative net profit).
    cumulative: (d) => ({
        chart: { type: 'area', height: 260 },
        series: [{ name: d.name ?? 'Total', data: d.values }],
        xaxis: { categories: d.labels },
        yaxis: { labels: { formatter: (v) => inr(v) } },
        colors: ['#8b5cf6'],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.02 } },
        markers: { size: 3 },
        tooltip: { y: { formatter: (v) => (v === null ? '—' : inr(v)) } },
    }),
});

const merge = (a, b) => {
    const out = { ...a };
    for (const [k, v] of Object.entries(b)) {
        out[k] = v && typeof v === 'object' && !Array.isArray(v) && typeof a[k] === 'object' ? merge(a[k], v) : v;
    }
    return out;
};

document.addEventListener('alpine:init', () => {
    // <div x-data="apexChart('trend', {...})"></div>  — re-created by Livewire when its wire:key changes.
    window.Alpine.data('apexChart', (type, data) => ({
        chart: null,
        init() {
            this.draw();
            this._onTheme = () => { this.chart?.destroy(); this.draw(); };
            window.addEventListener('theme-changed', this._onTheme);
        },
        draw() {
            this.chart = new ApexCharts(this.$el, merge(base(), presets[type](data)));
            this.chart.render();
        },
        // Alpine calls destroy() when Livewire removes the element.
        destroy() {
            window.removeEventListener('theme-changed', this._onTheme);
            this.chart?.destroy();
            this.chart = null;
        },
    }));
});
