<script setup>
import { computed, ref } from 'vue';
import { money } from '../../lib/isp';

// Grouped vertical bars (one axis), hover tooltip per group, legend + table fallback.
const props = defineProps({
    rows: { type: Array, default: () => [] }, // [{ label, [key]: number }]
    series: { type: Array, default: () => [] }, // [{ key, label, slot: 1..8 }]
    height: { type: Number, default: 220 },
});

const W = 720;
const pad = { top: 12, right: 8, bottom: 26, left: 56 };
const hover = ref(null);
const showTable = ref(false);

const max = computed(() => {
    const m = Math.max(0, ...props.rows.flatMap((r) => props.series.map((s) => Number(r[s.key] || 0))));
    if (m <= 0) return 1;
    const p = Math.pow(10, Math.floor(Math.log10(m)));
    return Math.ceil(m / p) * p;
});
const plotH = computed(() => props.height - pad.top - pad.bottom);
const band = computed(() => (W - pad.left - pad.right) / Math.max(1, props.rows.length));
const barW = computed(() => Math.max(3, Math.min(18, (band.value * 0.7) / props.series.length - 2)));
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => ({ v: max.value * f, y: pad.top + plotH.value * (1 - f) })));
const y = (v) => pad.top + plotH.value * (1 - Number(v || 0) / max.value);

function short(v) {
    return v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? (v / 1e3).toFixed(v >= 1e4 ? 0 : 1) + 'k' : String(Math.round(v));
}
// bar with 4px rounded top anchored to the baseline
function barPath(x, top, w, base) {
    const h = base - top;
    if (h <= 0) return '';
    const r = Math.min(4, w / 2, h);
    return `M${x},${base}V${top + r}Q${x},${top} ${x + r},${top}H${x + w - r}Q${x + w},${top} ${x + w},${top + r}V${base}Z`;
}
</script>

<template>
    <div class="viz">
        <div class="mb-2 flex flex-wrap items-center gap-4 text-xs text-slate-600">
            <span v-for="s in series" :key="s.key" class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: `var(--series-${s.slot})` }"></span>{{ s.label }}</span>
            <button type="button" class="ml-auto text-slate-400 hover:text-slate-600" @click="showTable = !showTable">{{ showTable ? 'Chart' : 'Table' }}</button>
        </div>
        <div v-if="!showTable" class="relative">
            <svg :viewBox="`0 0 ${W} ${height}`" class="h-auto w-full" role="img" :aria-label="series.map((s) => s.label).join(' vs ')" @mouseleave="hover = null">
                <g v-for="t in ticks" :key="t.v">
                    <line :x1="pad.left" :x2="W - pad.right" :y1="t.y" :y2="t.y" class="grid" />
                    <text :x="pad.left - 6" :y="t.y + 3" text-anchor="end" class="axis">{{ short(t.v) }}</text>
                </g>
                <g v-for="(r, i) in rows" :key="r.label">
                    <rect :x="pad.left + i * band" :y="pad.top" :width="band" :height="plotH" :class="hover === i ? 'hoverband' : 'hitband'" @mouseenter="hover = i" />
                    <path
                        v-for="(s, si) in series"
                        :key="s.key"
                        :d="barPath(pad.left + i * band + (band - (barW + 2) * series.length) / 2 + si * (barW + 2), y(r[s.key]), barW, pad.top + plotH)"
                        :style="{ fill: `var(--series-${s.slot})` }"
                        pointer-events="none"
                    />
                    <text :x="pad.left + i * band + band / 2" :y="height - 8" text-anchor="middle" class="axis">{{ r.label }}</text>
                </g>
            </svg>
            <div v-if="hover !== null" class="pointer-events-none absolute top-0 z-10 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs shadow-md"
                :style="{ left: `min(calc(${((pad.left + hover * band + band / 2) / W) * 100}% + 8px), calc(100% - 170px))` }">
                <div class="mb-0.5 font-medium text-slate-800">{{ rows[hover].label }}</div>
                <div v-for="s in series" :key="s.key" class="flex items-center gap-1.5 text-slate-600">
                    <span class="inline-block h-2 w-2 rounded-sm" :style="{ background: `var(--series-${s.slot})` }"></span>{{ s.label }}: <span class="font-medium text-slate-800">{{ money(rows[hover][s.key]) }}</span>
                </div>
            </div>
        </div>
        <table v-else class="w-full text-xs">
            <thead><tr class="border-b border-slate-200 text-left text-slate-500"><th class="py-1">Month</th><th v-for="s in series" :key="s.key" class="py-1 text-right">{{ s.label }}</th></tr></thead>
            <tbody><tr v-for="r in rows" :key="r.label" class="border-b border-slate-100"><td class="py-1">{{ r.label }}</td><td v-for="s in series" :key="s.key" class="py-1 text-right">{{ money(r[s.key]) }}</td></tr></tbody>
        </table>
    </div>
</template>

<style scoped>
.viz {
    --series-1: #2a78d6;
    --series-2: #eb6834;
    --grid: #e5e7eb;
    --axis: #6b7280;
}
:global(.dark) .viz {
    --series-1: #3987e5;
    --series-2: #d95926;
    --grid: #34373c;
    --axis: #9ca3af;
}
.grid { stroke: var(--grid); stroke-width: 1; }
.axis { fill: var(--axis); font-size: 10px; }
.hitband { fill: transparent; }
.hoverband { fill: var(--grid); opacity: 0.45; }
</style>
