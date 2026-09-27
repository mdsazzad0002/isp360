<script setup>
import { computed, ref } from 'vue';
import { money } from '../../lib/isp';

// Revenue and cost as grouped bars with profit as a line, all in the branch currency on one axis (profit can
// go below zero, so the baseline is the zero line). Hover a month for the numbers; the last
// profit point is labelled directly; a table view is always one click away.
const props = defineProps({
    rows: { type: Array, default: () => [] }, // [{ label, revenue, cost, profit }]
    height: { type: Number, default: 260 },
});

const W = 760;
const pad = { top: 16, right: 64, bottom: 26, left: 60 };
const hover = ref(null);
const showTable = ref(false);

// A 1/2/2.5/5 x 10^n step, so every tick is a round number and zero is always a tick.
function niceStep(range) {
    const raw = Math.max(range, 1) / 4;
    const p = Math.pow(10, Math.floor(Math.log10(raw)));
    return [1, 2, 2.5, 5, 10].map((m) => m * p).find((s) => s >= raw);
}
const scale = computed(() => {
    const values = props.rows.flatMap((r) => [r.revenue, r.cost, r.profit].map(Number));
    const hi = Math.max(0, ...values);
    const lo = Math.min(0, ...values);
    const step = niceStep(hi - lo);
    return { step, min: Math.floor(lo / step) * step, max: Math.max(step, Math.ceil(hi / step) * step) };
});
const maxV = computed(() => scale.value.max);
const minV = computed(() => scale.value.min);
const plotH = computed(() => props.height - pad.top - pad.bottom);
const y = (v) => pad.top + plotH.value * ((maxV.value - Number(v || 0)) / (maxV.value - minV.value));
const zeroY = computed(() => y(0));
const band = computed(() => (W - pad.left - pad.right) / Math.max(1, props.rows.length));
const barW = computed(() => Math.max(3, Math.min(20, band.value * 0.3)));
const cx = (i) => pad.left + i * band.value + band.value / 2;
const ticks = computed(() => {
    const out = [];
    for (let v = minV.value; v <= maxV.value + scale.value.step / 1000; v += scale.value.step) out.push({ v, y: y(v) });
    return out;
});
const linePath = computed(() => props.rows.map((r, i) => `${i ? 'L' : 'M'}${cx(i)},${y(r.profit)}`).join(''));
const last = computed(() => (props.rows.length ? { i: props.rows.length - 1, r: props.rows[props.rows.length - 1] } : null));

function short(v) {
    const a = Math.abs(v);
    const s = a >= 1e6 ? (a / 1e6).toFixed(1) + 'M' : a >= 1e3 ? (a / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k' : String(Math.round(a));
    return (v < 0 ? '−' : '') + s;
}
// bar with 4px rounded data-end, anchored to the zero line
function barPath(x, v, w) {
    const top = y(Math.max(0, v));
    const h = zeroY.value - top;
    if (h <= 0) return '';
    const r = Math.min(4, w / 2, h);
    return `M${x},${zeroY.value}V${top + r}Q${x},${top} ${x + r},${top}H${x + w - r}Q${x + w},${top} ${x + w},${top + r}V${zeroY.value}Z`;
}
</script>

<template>
    <div class="viz">
        <div class="mb-2 flex flex-wrap items-center gap-4 text-xs text-slate-600">
            <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: var(--series-1)"></span>Revenue</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: var(--series-2)"></span>Bandwidth cost</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-4" style="background: var(--series-3)"></span><span class="-ml-3 inline-block h-2 w-2 rounded-full" style="background: var(--series-3)"></span>Profit</span>
            <button type="button" class="ml-auto text-slate-400 hover:text-slate-600" @click="showTable = !showTable">{{ showTable ? 'Chart' : 'Table' }}</button>
        </div>
        <div v-if="!showTable" class="relative">
            <svg :viewBox="`0 0 ${W} ${height}`" class="h-auto w-full" role="img" aria-label="Revenue, bandwidth cost and profit by month" @mouseleave="hover = null">
                <g v-for="t in ticks" :key="t.v">
                    <line :x1="pad.left" :x2="W - pad.right" :y1="t.y" :y2="t.y" class="grid" />
                    <text :x="pad.left - 6" :y="t.y + 3" text-anchor="end" class="axis">{{ short(t.v) }}</text>
                </g>
                <line :x1="pad.left" :x2="W - pad.right" :y1="zeroY" :y2="zeroY" class="zero" />
                <g v-for="(r, i) in rows" :key="r.label">
                    <rect :x="pad.left + i * band" :y="pad.top" :width="band" :height="plotH" :class="hover === i ? 'hoverband' : 'hitband'" @mouseenter="hover = i" />
                    <path :d="barPath(cx(i) - barW - 1, r.revenue, barW)" style="fill: var(--series-1)" pointer-events="none" />
                    <path :d="barPath(cx(i) + 1, r.cost, barW)" style="fill: var(--series-2)" pointer-events="none" />
                    <text :x="cx(i)" :y="height - 8" text-anchor="middle" class="axis">{{ r.label }}</text>
                </g>
                <path :d="linePath" fill="none" stroke-width="2" style="stroke: var(--series-3)" pointer-events="none" />
                <circle v-for="(r, i) in rows" :key="`p${i}`" :cx="cx(i)" :cy="y(r.profit)" :r="hover === i ? 5 : 4" class="marker" style="fill: var(--series-3)" pointer-events="none" />
                <text v-if="last" :x="cx(last.i) + 8" :y="y(last.r.profit) + 3" class="label">{{ short(last.r.profit) }}</text>
            </svg>
            <div
                v-if="hover !== null"
                class="pointer-events-none absolute top-0 z-10 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs shadow-md dark:border-slate-700 dark:bg-slate-800"
                :style="{ left: `min(calc(${(cx(hover) / W) * 100}% + 10px), calc(100% - 180px))` }"
            >
                <div class="mb-0.5 font-medium text-slate-800 dark:text-slate-100">{{ rows[hover].label }}</div>
                <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300"><span class="inline-block h-2 w-2 rounded-sm" style="background: var(--series-1)"></span>Revenue: <b class="text-slate-800 dark:text-slate-100">{{ money(rows[hover].revenue) }}</b></div>
                <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300"><span class="inline-block h-2 w-2 rounded-sm" style="background: var(--series-2)"></span>Cost: <b class="text-slate-800 dark:text-slate-100">{{ money(rows[hover].cost) }}</b></div>
                <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300"><span class="inline-block h-2 w-2 rounded-full" style="background: var(--series-3)"></span>Profit: <b class="text-slate-800 dark:text-slate-100">{{ money(rows[hover].profit) }}</b><span v-if="rows[hover].margin !== null" class="text-slate-400">({{ rows[hover].margin }}%)</span></div>
            </div>
        </div>
        <table v-else class="w-full text-xs">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500"><th class="py-1">Month</th><th class="py-1 text-right">Revenue</th><th class="py-1 text-right">Cost</th><th class="py-1 text-right">Profit</th><th class="py-1 text-right">Margin</th></tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.label" class="border-b border-slate-100">
                    <td class="py-1">{{ r.label }}</td>
                    <td class="py-1 text-right">{{ money(r.revenue) }}</td>
                    <td class="py-1 text-right">{{ money(r.cost) }}</td>
                    <td class="py-1 text-right" :class="r.profit < 0 ? 'text-red-600' : ''">{{ money(r.profit) }}</td>
                    <td class="py-1 text-right">{{ r.margin !== null ? `${r.margin}%` : '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.viz {
    --series-1: #2a78d6;
    --series-2: #eb6834;
    --series-3: #1baf7a;
    --surface: #ffffff;
    --grid: #e5e7eb;
    --axis: #6b7280;
    --ink: #374151;
}
:global(.dark .viz) {
    --series-1: #3987e5;
    --series-2: #d95926;
    --series-3: #199e70;
    --surface: #1e293b;
    --grid: #34373c;
    --axis: #9ca3af;
    --ink: #e5e7eb;
}
.grid { stroke: var(--grid); stroke-width: 1; }
.zero { stroke: var(--axis); stroke-width: 1; opacity: 0.6; }
.axis { fill: var(--axis); font-size: 10px; }
.label { fill: var(--ink); font-size: 11px; font-weight: 600; }
.marker { stroke: var(--surface); stroke-width: 2; }
.hitband { fill: transparent; }
.hoverband { fill: var(--grid); opacity: 0.45; }
</style>
