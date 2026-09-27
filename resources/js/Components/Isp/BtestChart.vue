<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

// Bandwidth-test result drawn inside the (dark) terminal. Two series on one Mbps axis:
// download = router → target (tx), upload = target → router (rx). Colours are the
// dataviz palette's dark steps, validated against the terminal surface (#020617).
const props = defineProps({ chart: { type: Object, required: true } });

const SERIES = [
    { key: 'tx', name: 'Download', hint: 'router → target', color: '#3987e5', dirs: ['both', 'transmit'] },
    { key: 'rx', name: 'Upload', hint: 'target → router', color: '#d95926', dirs: ['both', 'receive'] },
];
const series = computed(() => SERIES.filter((s) => s.dirs.includes(props.chart.direction || 'both')));

const box = ref(null);
const width = ref(560);
const HEIGHT = 200;
const PAD = { top: 22, right: 128, bottom: 24, left: 44 };
let observer;
onMounted(() => {
    const measure = () => (width.value = Math.max(280, box.value?.clientWidth || 560));
    measure();
    observer = new ResizeObserver(measure);
    if (box.value) observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());

const toMbps = (bps) => Number(bps || 0) / 1e6;
const fmt = (mbps) => (mbps >= 100 ? mbps.toFixed(0) : mbps >= 10 ? mbps.toFixed(1) : mbps.toFixed(2)) + ' Mbps';

const points = computed(() => props.chart.points || []);
const tMin = computed(() => Math.min(...points.value.map((p) => p.t)));
const tMax = computed(() => Math.max(tMin.value + 1, ...points.value.map((p) => p.t)));
const ref_ = computed(() => {
    const pkg = props.chart.package_mbps;
    if (!pkg) return null;
    const v = props.chart.direction === 'receive' ? pkg.up : pkg.down;
    return v > 0 ? v : null;
});
// Readable y steps (1/2/2.5/5 × 10^n), about four of them, top above the peak and the package line.
const yScale = computed(() => {
    const peak = Math.max(0.1, ref_.value || 0, ...points.value.flatMap((p) => series.value.map((s) => toMbps(p[s.key]))));
    const raw = peak * 1.1;
    const mag = 10 ** Math.floor(Math.log10(raw / 4));
    const step = [1, 2, 2.5, 5, 10].find((m) => m * mag >= raw / 4) * mag;
    return { step, max: Math.ceil(raw / step) * step };
});
const yMax = computed(() => yScale.value.max);
const ticks = computed(() => Array.from({ length: Math.round(yScale.value.max / yScale.value.step) + 1 }, (_, i) => +(i * yScale.value.step).toFixed(3)));

const x = (t) => PAD.left + ((t - tMin.value) / (tMax.value - tMin.value)) * (width.value - PAD.left - PAD.right);
const y = (mbps) => PAD.top + (1 - mbps / yMax.value) * (HEIGHT - PAD.top - PAD.bottom);

const paths = computed(() =>
    series.value.map((s) => ({
        ...s,
        d: points.value.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(toMbps(p[s.key])).toFixed(1)}`).join(' '),
        last: points.value.length ? toMbps(points.value[points.value.length - 1][s.key]) : 0,
        avg: toMbps(props.chart[`${s.key}_avg`]),
    })),
);
// Direct labels at the line ends; nudge apart when they would collide.
const endLabels = computed(() => {
    const labels = paths.value.map((p) => ({ ...p, ly: y(p.last) }));
    if (labels.length === 2 && Math.abs(labels[0].ly - labels[1].ly) < 14) {
        const mid = (labels[0].ly + labels[1].ly) / 2;
        const [hi, lo] = labels[0].ly <= labels[1].ly ? [0, 1] : [1, 0];
        labels[hi].ly = mid - 7;
        labels[lo].ly = mid + 7;
    }
    return labels;
});

// Crosshair: snap to the nearest second.
const hover = ref(null);
function onMove(e) {
    const rect = e.currentTarget.getBoundingClientRect();
    const px = e.clientX - rect.left;
    let best = null;
    for (const p of points.value) {
        if (!best || Math.abs(x(p.t) - px) < Math.abs(x(best.t) - px)) best = p;
    }
    hover.value = best;
}
const tipLeft = computed(() => (hover.value ? Math.min(x(hover.value.t) + 10, width.value - 150) : 0));

const showTable = ref(false);
</script>

<template>
    <div class="my-2 rounded-md border border-slate-800 bg-[#020617] p-3 font-sans text-slate-300" @click.stop>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div class="text-xs text-slate-400">Bandwidth test · {{ chart.title }}</div>
            <div class="flex items-center gap-3 text-xs">
                <span v-for="s in series" :key="s.key" class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-4 rounded" :style="{ background: s.color }"></span>
                    <span class="text-slate-300">{{ s.name }}</span>
                    <span class="text-slate-500">{{ s.hint }}</span>
                </span>
                <button type="button" class="rounded border border-slate-700 px-1.5 py-0.5 text-[11px] text-slate-400 hover:text-slate-200" @click="showTable = !showTable">{{ showTable ? 'Graph' : 'Table' }}</button>
            </div>
        </div>

        <div class="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div v-for="p in paths" :key="p.key" class="rounded border border-slate-800 px-2 py-1">
                <div class="text-[10px] uppercase tracking-wide text-slate-500">{{ p.name }} avg</div>
                <div class="text-base font-semibold text-slate-100">{{ fmt(p.avg) }}</div>
            </div>
            <div v-if="ref_" class="rounded border border-slate-800 px-2 py-1">
                <div class="text-[10px] uppercase tracking-wide text-slate-500">Package</div>
                <div class="text-base font-semibold text-slate-100">{{ ref_ }} Mbps</div>
            </div>
            <div v-if="ref_ && paths[0]" class="rounded border border-slate-800 px-2 py-1">
                <div class="text-[10px] uppercase tracking-wide text-slate-500">{{ paths[0].name }} vs package</div>
                <div class="text-base font-semibold text-slate-100">{{ Math.round((paths[0].avg / ref_) * 100) }}%</div>
            </div>
        </div>

        <div v-show="!showTable" ref="box" class="relative">
            <svg :width="width" :height="HEIGHT" class="block" role="img" :aria-label="`Bandwidth test graph, ${paths.map((p) => p.name + ' average ' + fmt(p.avg)).join(', ')}`" @pointermove="onMove" @pointerleave="hover = null">
                <!-- grid + y axis -->
                <g v-for="t in ticks" :key="t">
                    <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" stroke="#1e293b" stroke-width="1" />
                    <text :x="PAD.left - 6" :y="y(t) + 3" text-anchor="end" font-size="10" fill="#64748b">{{ t }}</text>
                </g>
                <text :x="PAD.left" :y="10" text-anchor="start" font-size="10" fill="#64748b">Mbps</text>
                <!-- x axis -->
                <text v-for="p in points" v-show="points.length <= 16 || p.t % 2 === 0" :key="'x' + p.t" :x="x(p.t)" :y="HEIGHT - 8" text-anchor="middle" font-size="10" fill="#64748b">{{ p.t }}s</text>
                <!-- package speed reference -->
                <g v-if="ref_">
                    <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(ref_)" :y2="y(ref_)" stroke="#94a3b8" stroke-width="1" stroke-dasharray="4 4" />
                    <text :x="PAD.left + 4" :y="y(ref_) - 4" font-size="10" fill="#94a3b8">package {{ ref_ }} Mbps</text>
                </g>
                <!-- series -->
                <path v-for="p in paths" :key="p.key" :d="p.d" fill="none" :stroke="p.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                <g v-for="p in paths" :key="'pt' + p.key">
                    <circle v-for="pt in points" :key="pt.t" :cx="x(pt.t)" :cy="y(toMbps(pt[p.key]))" r="2.5" :fill="p.color" stroke="#020617" stroke-width="1.5" />
                </g>
                <!-- direct labels at the end -->
                <text v-for="l in endLabels" :key="'l' + l.key" :x="width - PAD.right + 6" :y="l.ly + 3" font-size="10" fill="#cbd5e1">{{ l.name }} {{ fmt(l.last) }}</text>
                <!-- crosshair -->
                <g v-if="hover">
                    <line :x1="x(hover.t)" :x2="x(hover.t)" :y1="PAD.top" :y2="HEIGHT - PAD.bottom" stroke="#475569" stroke-width="1" />
                    <circle v-for="p in paths" :key="'h' + p.key" :cx="x(hover.t)" :cy="y(toMbps(hover[p.key]))" r="4" :fill="p.color" stroke="#020617" stroke-width="2" />
                </g>
            </svg>
            <div v-if="hover" class="pointer-events-none absolute top-1 rounded border border-slate-700 bg-slate-900/95 px-2 py-1 text-[11px] shadow" :style="{ left: tipLeft + 'px' }">
                <div class="text-slate-400">{{ hover.t }}s</div>
                <div v-for="p in paths" :key="'t' + p.key" class="flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-3 rounded" :style="{ background: p.color }"></span>
                    <b class="text-slate-100">{{ fmt(toMbps(hover[p.key])) }}</b>
                    <span class="text-slate-400">{{ p.name }}</span>
                </div>
            </div>
        </div>

        <table v-if="showTable" class="w-full text-xs">
            <thead>
                <tr class="border-b border-slate-800 text-start text-slate-500">
                    <th class="py-1 font-medium">Second</th>
                    <th v-for="p in paths" :key="p.key" class="py-1 text-end font-medium">{{ p.name }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="pt in points" :key="pt.t" class="border-b border-slate-900">
                    <td class="py-0.5 text-slate-400">{{ pt.t }}s</td>
                    <td v-for="p in paths" :key="p.key" class="py-0.5 text-end text-slate-200">{{ fmt(toMbps(pt[p.key])) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
