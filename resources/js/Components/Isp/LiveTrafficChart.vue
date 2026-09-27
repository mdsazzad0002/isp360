<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import axios from 'axios';

// Real-time download/upload of a connection's live session, read from its router every
// POLL_MS while the panel is open. Two series on one Mbps axis; the package speed is a
// dashed reference. Colours: dataviz palette slots 1-2 (light), validated on white.
const props = defineProps({
    connectionId: { type: Number, required: true },
    packageMbps: { type: Object, default: null }, // { down, up }
});

const POLL_MS = 2000;
const WINDOW = 60; // samples kept = 2 minutes
const AUTO_PAUSE_MS = 5 * 60 * 1000; // a forgotten open panel must not poll the router forever
const SERIES = [
    { key: 'down', name: 'Download', hint: 'to customer', color: '#2a78d6' },
    { key: 'up', name: 'Upload', hint: 'from customer', color: '#eb6834' },
];

const points = ref([]); // { at (ms), down (bps), up (bps) }
const state = ref({ status: 'loading', text: '' }); // loading | live | offline | error | unmanaged
const paused = ref(false);
const autoPaused = ref(false);
let timer = null;
let startedAt = 0;
let lastBytes = null; // hotspot: previous byte counters, to derive a rate
let alive = true;

async function poll() {
    timer = null;
    if (!alive || paused.value) return;
    if (Date.now() - startedAt > AUTO_PAUSE_MS) {
        paused.value = true;
        autoPaused.value = true;
        return;
    }
    if (document.visibilityState === 'visible') {
        try {
            const { data } = await axios.post('/isp/connection-traffic', { id: props.connectionId });
            if (!alive) return;
            if (!data.managed) state.value = { status: 'unmanaged', text: 'No router session to read (no router, or not PPPoE/hotspot).' };
            else if (data.error) state.value = { status: 'error', text: data.error };
            else if (!data.online) {
                state.value = { status: 'offline', text: 'Customer is offline: no traffic.' };
                lastBytes = null;
            } else {
                state.value = { status: 'live', text: data.sample.address || '' };
                add(data.sample, data.at * 1000);
            }
        } catch (e) {
            if (!alive) return;
            state.value = { status: 'error', text: e.response?.status === 429 ? 'Too many requests, slowing down.' : e.response?.data?.message || e.message };
        }
        if (state.value.status === 'unmanaged') return; // nothing will change by asking again
    }
    // next request only after this one answered, so a slow router never gets a queue of them
    // slower while offline or failing: nothing moves, and the router is spared
    timer = setTimeout(poll, state.value.status === 'live' ? POLL_MS : POLL_MS * 3);
}

function add(s, at) {
    let down = s.down_bps;
    let up = s.up_bps;
    if (down === undefined) {
        // hotspot: rate from the change in byte counters since the last reading
        const prev = lastBytes;
        lastBytes = { at, down: s.down_bytes, up: s.up_bytes };
        if (!prev || at <= prev.at || s.down_bytes < prev.down) return; // first reading or counters reset
        const secs = (at - prev.at) / 1000;
        down = ((s.down_bytes - prev.down) * 8) / secs;
        up = ((s.up_bytes - prev.up) * 8) / secs;
    }
    points.value = [...points.value, { at, down, up }].slice(-WINDOW);
}

function start() {
    paused.value = false;
    autoPaused.value = false;
    startedAt = Date.now();
    clearTimeout(timer);
    poll();
}
function togglePause() {
    if (paused.value) start();
    else {
        paused.value = true;
        clearTimeout(timer);
        timer = null;
    }
}
function onVisible() {
    if (document.visibilityState === 'visible' && !paused.value && !timer) poll();
}
onMounted(() => {
    start();
    document.addEventListener('visibilitychange', onVisible);
});
onBeforeUnmount(() => {
    alive = false;
    clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisible);
    observer?.disconnect();
});
watch(
    () => props.connectionId,
    () => {
        points.value = [];
        lastBytes = null;
        state.value = { status: 'loading', text: '' };
        start();
    },
);

// ---- chart geometry
const box = ref(null);
const width = ref(480);
const HEIGHT = 170;
const PAD = { top: 16, right: 12, bottom: 22, left: 40 };
let observer;
// the plot box appears only after the first reading, so observe it whenever it is (re)created
watch(box, (el) => {
    observer?.disconnect();
    if (!el) return;
    const measure = () => (width.value = Math.max(260, el.clientWidth || 480));
    measure();
    observer = new ResizeObserver(measure);
    observer.observe(el);
});

const toMbps = (bps) => Number(bps || 0) / 1e6;
const fmt = (mbps) => (mbps >= 100 ? mbps.toFixed(0) : mbps >= 10 ? mbps.toFixed(1) : mbps.toFixed(2)) + ' Mbps';
const pkgDown = computed(() => (props.packageMbps?.down > 0 ? Number(props.packageMbps.down) : null));

const last = computed(() => points.value[points.value.length - 1] || null);
const peak = computed(() => Math.max(0, ...points.value.map((p) => toMbps(p.down))));
// Readable y steps (1/2/2.5/5 × 10^n), about four, top above the peak and the package line.
const yScale = computed(() => {
    const top = Math.max(0.1, pkgDown.value || 0, ...points.value.flatMap((p) => [toMbps(p.down), toMbps(p.up)]));
    const raw = top * 1.1;
    const mag = 10 ** Math.floor(Math.log10(raw / 4));
    const step = [1, 2, 2.5, 5, 10].find((m) => m * mag >= raw / 4) * mag;
    return { step, max: Math.ceil(raw / step) * step };
});
const ticks = computed(() => Array.from({ length: Math.round(yScale.value.max / yScale.value.step) + 1 }, (_, i) => +(i * yScale.value.step).toFixed(3)));

// x = seconds before the newest reading, over a fixed 2-minute window so the line scrolls
const SPAN = (WINDOW * POLL_MS) / 1000;
const now = computed(() => last.value?.at || 0);
const x = (at) => PAD.left + (1 - (now.value - at) / 1000 / SPAN) * (width.value - PAD.left - PAD.right);
const y = (mbps) => PAD.top + (1 - mbps / yScale.value.max) * (HEIGHT - PAD.top - PAD.bottom);
const xTicks = [SPAN, SPAN / 2, 0];

const paths = computed(() =>
    SERIES.map((s) => ({
        ...s,
        d: points.value.map((p, i) => `${i ? 'L' : 'M'}${x(p.at).toFixed(1)},${y(toMbps(p[s.key])).toFixed(1)}`).join(' '),
        area: points.value.length > 1
            ? `M${x(points.value[0].at).toFixed(1)},${y(0)} ` + points.value.map((p) => `L${x(p.at).toFixed(1)},${y(toMbps(p[s.key])).toFixed(1)}`).join(' ') + ` L${x(last.value.at).toFixed(1)},${y(0)} Z`
            : '',
    })),
);

// Crosshair: snap to the nearest reading.
const hover = ref(null);
function onMove(e) {
    const px = e.clientX - e.currentTarget.getBoundingClientRect().left;
    let best = null;
    for (const p of points.value) if (!best || Math.abs(x(p.at) - px) < Math.abs(x(best.at) - px)) best = p;
    hover.value = best;
}
const tipLeft = computed(() => (hover.value ? Math.min(Math.max(x(hover.value.at) + 10, 0), width.value - 150) : 0));
const ago = (at) => Math.round((now.value - at) / 1000);
const clock = (at) => new Date(at).toLocaleTimeString();

const showTable = ref(false);
</script>

<template>
    <div class="rounded-md border border-slate-200 p-2">
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 text-xs font-medium text-slate-700">
                <i class="bi bi-activity"></i> Live traffic
                <span v-if="state.status === 'live' && !paused" class="inline-flex items-center gap-1 font-normal text-slate-500"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-slate-500"></span>every {{ POLL_MS / 1000 }}s</span>
                <span v-else-if="paused" class="font-normal text-slate-500">paused</span>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <span v-for="s in SERIES" :key="s.key" class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-4 rounded" :style="{ background: s.color }"></span>
                    <span class="text-slate-700">{{ s.name }}</span>
                </span>
                <button type="button" class="rounded border border-slate-300 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50" @click="showTable = !showTable">{{ showTable ? 'Graph' : 'Table' }}</button>
                <button type="button" class="rounded border border-slate-300 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50" :title="paused ? 'Resume' : 'Pause'" @click="togglePause"><i class="bi" :class="paused ? 'bi-play-fill' : 'bi-pause-fill'"></i></button>
            </div>
        </div>

        <div class="mb-1.5 grid grid-cols-2 gap-1.5 sm:grid-cols-4">
            <div v-for="s in SERIES" :key="s.key" class="rounded border border-slate-100 px-2 py-1">
                <div class="flex items-center gap-1 text-[10px] uppercase tracking-wide text-slate-500"><span class="inline-block h-2 w-2 rounded-sm" :style="{ background: s.color }"></span>{{ s.name }} now</div>
                <div class="text-sm font-semibold text-slate-900">{{ last ? fmt(toMbps(last[s.key])) : '—' }}</div>
            </div>
            <div class="rounded border border-slate-100 px-2 py-1">
                <div class="text-[10px] uppercase tracking-wide text-slate-500">Peak download</div>
                <div class="text-sm font-semibold text-slate-900">{{ points.length ? fmt(peak) : '—' }}</div>
            </div>
            <div class="rounded border border-slate-100 px-2 py-1">
                <div class="text-[10px] uppercase tracking-wide text-slate-500">Package</div>
                <div class="text-sm font-semibold text-slate-900">{{ pkgDown ? `${pkgDown} / ${packageMbps.up || '—'} Mbps` : '—' }}</div>
            </div>
        </div>

        <div v-if="autoPaused" class="mb-1.5 rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">Paused after 5 minutes to spare the router. <button type="button" class="font-medium text-brand-600 underline" @click="start">Resume</button></div>
        <div v-else-if="state.status !== 'live' && !points.length" class="flex h-[120px] items-center justify-center rounded bg-slate-50 px-3 text-center text-xs" :class="state.status === 'error' ? 'text-red-600' : 'text-slate-500'">
            <span v-if="state.status === 'loading'"><i class="bi bi-hourglass-split"></i> Reading traffic from the router…</span>
            <span v-else>{{ state.text }}</span>
        </div>

        <template v-if="points.length">
            <div v-if="state.status !== 'live'" class="mb-1 text-[11px]" :class="state.status === 'error' ? 'text-red-600' : 'text-slate-500'">{{ state.text }} Showing the last readings.</div>
            <div v-show="!showTable" ref="box" class="relative">
                <svg :width="width" :height="HEIGHT" class="block touch-none" role="img" :aria-label="`Live traffic: download ${fmt(toMbps(last.down))}, upload ${fmt(toMbps(last.up))}`" @pointermove="onMove" @pointerleave="hover = null">
                    <g v-for="t in ticks" :key="t">
                        <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" stroke="#eef2f6" stroke-width="1" />
                        <text :x="PAD.left - 6" :y="y(t) + 3" text-anchor="end" font-size="10" fill="#64748b">{{ t }}</text>
                    </g>
                    <text :x="PAD.left" :y="9" font-size="10" fill="#64748b">Mbps</text>
                    <text v-for="s in xTicks" :key="s" :x="PAD.left + (1 - s / SPAN) * (width - PAD.left - PAD.right)" :y="HEIGHT - 6" :text-anchor="s === 0 ? 'end' : s === SPAN ? 'start' : 'middle'" font-size="10" fill="#64748b">{{ s ? `-${s}s` : 'now' }}</text>
                    <g v-if="pkgDown">
                        <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(pkgDown)" :y2="y(pkgDown)" stroke="#94a3b8" stroke-width="1" stroke-dasharray="4 4" />
                        <text :x="PAD.left + 4" :y="y(pkgDown) - 4" font-size="10" fill="#64748b">package {{ pkgDown }} Mbps</text>
                    </g>
                    <path v-for="p in paths" :key="'a' + p.key" :d="p.area" :fill="p.color" fill-opacity="0.08" />
                    <path v-for="p in paths" :key="p.key" :d="p.d" fill="none" :stroke="p.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    <circle v-for="p in paths" :key="'e' + p.key" :cx="x(last.at)" :cy="y(toMbps(last[p.key]))" r="4" :fill="p.color" stroke="#fff" stroke-width="2" />
                    <g v-if="hover">
                        <line :x1="x(hover.at)" :x2="x(hover.at)" :y1="PAD.top" :y2="HEIGHT - PAD.bottom" stroke="#94a3b8" stroke-width="1" />
                        <circle v-for="p in paths" :key="'h' + p.key" :cx="x(hover.at)" :cy="y(toMbps(hover[p.key]))" r="4" :fill="p.color" stroke="#fff" stroke-width="2" />
                    </g>
                </svg>
                <div v-if="hover" class="pointer-events-none absolute top-2 rounded border border-slate-200 bg-white px-2 py-1 text-[11px] shadow-sm" :style="{ left: tipLeft + 'px' }">
                    <div class="text-slate-500">{{ clock(hover.at) }} · {{ ago(hover.at) ? `${ago(hover.at)}s ago` : 'now' }}</div>
                    <div v-for="s in SERIES" :key="s.key" class="flex items-center gap-1.5">
                        <span class="inline-block h-2 w-2 rounded-sm" :style="{ background: s.color }"></span>
                        <span class="text-slate-600">{{ s.name }}</span>
                        <span class="ml-auto pl-3 font-medium text-slate-900">{{ fmt(toMbps(hover[s.key])) }}</span>
                    </div>
                </div>
            </div>
            <div v-if="showTable" class="max-h-48 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-white text-left text-slate-500">
                        <tr><th class="py-1 font-medium">Time</th><th class="py-1 text-right font-medium">Download</th><th class="py-1 text-right font-medium">Upload</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in [...points].reverse()" :key="p.at" class="border-t border-slate-100">
                            <td class="py-0.5 text-slate-600">{{ clock(p.at) }}</td>
                            <td class="py-0.5 text-right text-slate-900">{{ fmt(toMbps(p.down)) }}</td>
                            <td class="py-0.5 text-right text-slate-900">{{ fmt(toMbps(p.up)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-1 text-[10px] text-slate-500">Download = router → customer, upload = customer → router. Near the dashed line = the customer is using the full package.</div>
        </template>
    </div>
</template>
