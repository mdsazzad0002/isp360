<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import LiveTrafficChart from '../../Components/Isp/LiveTrafficChart.vue';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';

// Live monitor of one MikroTik router (roadmap 4.6, step 1). One overview request every POLL_MS
// (resources, health, interfaces with byte counters, online users); interface rates come from the
// change in counters between two readings. The graph polls its own interface separately.
defineOptions({ layout: AppLayout });
const props = defineProps({ router: { type: Object, required: true } });
const toast = useToast();
const showError = useApiError();

const POLL_MS = 5000;
const HISTORY = 60; // sparkline readings = 5 minutes
const AUTO_PAUSE_MS = 5 * 60 * 1000; // a forgotten tab must not poll the router forever

const isRadius = props.router.driver === 'radius';
const data = ref(null); // last overview
const error = ref('');
const history = ref([]); // { at, cpu, mem, online }
const rates = ref({}); // name -> { rx, tx } bps
const paused = ref(false);
const autoPaused = ref(false);
let prevCounters = {}; // name -> { at, rx, tx }
let timer = null;
let startedAt = 0;
let alive = true;

async function poll() {
    timer = null;
    if (!alive || paused.value || isRadius) return;
    if (Date.now() - startedAt > AUTO_PAUSE_MS) {
        paused.value = true;
        autoPaused.value = true;
        return;
    }
    if (document.visibilityState === 'visible') {
        try {
            const res = await axios.post('/isp/router-monitor', { id: props.router.id });
            if (!alive) return;
            error.value = '';
            take(res.data);
        } catch (e) {
            if (!alive) return;
            error.value = e.response?.status === 429 ? 'Too many requests, slowing down.' : e.response?.data?.message || e.message;
        }
    }
    // next request only after this one answered; slower while failing, to spare the router
    timer = setTimeout(poll, error.value ? POLL_MS * 3 : POLL_MS);
}

function take(d) {
    const at = d.at * 1000;
    const next = {};
    const counters = {};
    for (const i of d.interfaces) {
        counters[i.name] = { at, rx: i.rx_bytes, tx: i.tx_bytes };
        const p = prevCounters[i.name];
        // no rate on the first reading or after a counter reset (router reboot, counters cleared)
        if (p && at > p.at && i.rx_bytes >= p.rx && i.tx_bytes >= p.tx) {
            const secs = (at - p.at) / 1000;
            next[i.name] = { rx: ((i.rx_bytes - p.rx) * 8) / secs, tx: ((i.tx_bytes - p.tx) * 8) / secs };
        }
    }
    prevCounters = counters;
    rates.value = next;
    data.value = d;
    const r = d.resource;
    history.value = [...history.value, { at, cpu: r.cpu, mem: memPct(r), online: d.online }].slice(-HISTORY);
    if (graphed.value === null) graphed.value = pins.value.find((n) => d.interfaces.some((i) => i.name === n)) ?? null;
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
});

// ---- formatting
const memPct = (r) => (r.memory_total ? (100 * (r.memory_total - r.memory_free)) / r.memory_total : null);
const diskPct = (r) => (r.disk_total ? (100 * (r.disk_total - r.disk_free)) / r.disk_total : null);
function bytes(n) {
    if (n === null || n === undefined) return '—';
    const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n >= 100 || i === 0 ? n.toFixed(0) : n.toFixed(1)} ${u[i]}`;
}
function bps(n) {
    if (n === null || n === undefined) return '—';
    if (n >= 1e9) return (n / 1e9).toFixed(2) + ' Gbps';
    if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e8 ? 0 : 1) + ' Mbps';
    if (n >= 1e3) return (n / 1e3).toFixed(0) + ' kbps';
    return n.toFixed(0) + ' bps';
}
const pct = (v) => (v === null || v === undefined ? '—' : `${Math.round(v)}%`);
// reserved status colours, always with an icon and a word
const level = (v) => (v === null || v === undefined ? null : v >= 90 ? 'critical' : v >= 75 ? 'warning' : null);

// ---- sparklines (one series, fixed 0-100 scale for percentages)
const SPARK_W = 120;
const SPARK_H = 28;
function spark(key, max) {
    const pts = history.value.filter((h) => h[key] !== null && h[key] !== undefined);
    if (pts.length < 2) return '';
    const top = max ?? Math.max(1, ...pts.map((h) => h[key]));
    const first = pts[0].at;
    const span = Math.max(1, pts[pts.length - 1].at - first);
    return pts.map((h, i) => `${i ? 'L' : 'M'}${(((h.at - first) / span) * (SPARK_W - 4) + 2).toFixed(1)},${(SPARK_H - 2 - (h[key] / top) * (SPARK_H - 4)).toFixed(1)}`).join(' ');
}

// ---- interfaces
const pins = ref([...(props.router.monitor_interfaces || [])]);
const graphed = ref(null);
const onlyRunning = ref(false);
const search = ref('');
const interfaces = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (data.value?.interfaces || [])
        .filter((i) => (!onlyRunning.value || (i.running && !i.disabled)) && (!q || i.name.toLowerCase().includes(q) || i.comment.toLowerCase().includes(q) || i.type.toLowerCase().includes(q)))
        .sort((a, b) => pins.value.includes(b.name) - pins.value.includes(a.name) || (b.running && !b.disabled) - (a.running && !a.disabled) || a.name.localeCompare(b.name));
});
const runningCount = computed(() => (data.value?.interfaces || []).filter((i) => i.running && !i.disabled).length);
const savingPins = ref(false);
async function togglePin(name) {
    const next = pins.value.includes(name) ? pins.value.filter((n) => n !== name) : [...pins.value, name];
    if (next.length > 4) return toast.error('Pin at most 4 interfaces.');
    savingPins.value = true;
    try {
        const res = await axios.post('/isp/router-monitor-pins', { id: props.router.id, interfaces: next });
        pins.value = res.data.interfaces;
    } catch (err) {
        showError(err);
    } finally {
        savingPins.value = false;
    }
}
function status(i) {
    if (i.disabled) return { label: 'Disabled', icon: 'bi-slash-circle', cls: 'text-slate-500' };
    if (i.running) return { label: 'Running', icon: 'bi-check-circle-fill', cls: 'text-emerald-700' };
    return { label: 'Down', icon: 'bi-x-circle-fill', cls: 'text-red-600' };
}
const r = computed(() => data.value?.resource || null);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <Link href="/isp/routers" class="text-xs text-brand-600 hover:underline"><i class="bi bi-arrow-left"></i> Routers</Link>
                <h1 class="text-base font-semibold text-slate-800">Live monitor: {{ router.name }}</h1>
                <div class="text-xs text-slate-500">
                    <span class="font-mono">{{ router.host }}{{ router.port ? ':' + router.port : '' }}</span>
                    <template v-if="r"> · {{ r.board }} · RouterOS {{ r.version }}<template v-if="r.architecture"> · {{ r.architecture }}</template></template>
                </div>
            </div>
            <div v-if="!isRadius" class="flex items-center gap-2 text-xs">
                <span v-if="!paused && data && !error" class="inline-flex items-center gap-1 text-slate-500"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-slate-500"></span>every {{ POLL_MS / 1000 }}s</span>
                <span v-else-if="paused" class="text-slate-500">paused</span>
                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-slate-600 hover:bg-slate-50" @click="togglePause"><i class="bi" :class="paused ? 'bi-play-fill' : 'bi-pause-fill'"></i> {{ paused ? 'Resume' : 'Pause' }}</button>
            </div>
        </div>

        <div v-if="isRadius" class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <i class="bi bi-info-circle"></i> {{ router.name }} is a RADIUS NAS. The live monitor reads a MikroTik router over its API; a RADIUS NAS only reports sessions (Routers → Online users).
        </div>

        <template v-else>
            <div v-if="autoPaused" class="rounded-md bg-slate-100 px-3 py-2 text-xs text-slate-600">Paused after 5 minutes to spare the router. <button type="button" class="font-medium text-brand-600 underline" @click="start">Resume</button></div>
            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"><i class="bi bi-exclamation-triangle-fill"></i> {{ error }}<template v-if="data"> Showing the last reading.</template></div>
            <div v-if="!data && !error" class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-400 shadow-sm"><i class="bi bi-hourglass-split"></i> Reading the router…</div>

            <template v-if="data">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <div v-for="t in [
                        { key: 'cpu', label: 'CPU', value: pct(r.cpu), sub: r.cpu_count ? `${r.cpu_count} core${r.cpu_count > 1 ? 's' : ''}` : '', lvl: level(r.cpu), max: 100 },
                        { key: 'mem', label: 'Memory', value: pct(memPct(r)), sub: `${bytes(r.memory_total - r.memory_free)} of ${bytes(r.memory_total)}`, lvl: level(memPct(r)), max: 100 },
                        { key: 'online', label: 'Online users', value: data.online.toLocaleString(), sub: 'PPPoE + Hotspot', lvl: null, max: null },
                    ]" :key="t.key" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-xs text-slate-500">{{ t.label }}</div>
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold text-slate-800">{{ t.value }}</span>
                            <span v-if="t.lvl" class="text-xs font-medium" :class="t.lvl === 'critical' ? 'text-red-600' : 'text-amber-700'"><i class="bi bi-exclamation-triangle-fill"></i> {{ t.lvl === 'critical' ? 'critical' : 'high' }}</span>
                        </div>
                        <div class="text-xs text-slate-500">{{ t.sub }}</div>
                        <svg :width="SPARK_W" :height="SPARK_H" class="mt-1 block max-w-full" role="img" :aria-label="`${t.label}, last ${history.length} readings`">
                            <title>{{ t.label }}: last {{ Math.round((history.length * POLL_MS) / 60000) || 1 }} min</title>
                            <line x1="0" :x2="SPARK_W" :y1="SPARK_H - 2" :y2="SPARK_H - 2" stroke="#eef2f6" stroke-width="1" />
                            <path :d="spark(t.key, t.max)" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                        </svg>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-xs text-slate-500">Disk</div>
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold text-slate-800">{{ pct(diskPct(r)) }}</span>
                            <span v-if="level(diskPct(r))" class="text-xs font-medium" :class="level(diskPct(r)) === 'critical' ? 'text-red-600' : 'text-amber-700'"><i class="bi bi-exclamation-triangle-fill"></i> {{ level(diskPct(r)) === 'critical' ? 'critical' : 'high' }}</span>
                        </div>
                        <div class="text-xs text-slate-500">{{ bytes(r.disk_total - r.disk_free) }} of {{ bytes(r.disk_total) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-xs text-slate-500">Uptime</div>
                        <div class="text-lg font-semibold text-slate-800">{{ r.uptime || '—' }}</div>
                        <div class="text-xs text-slate-500">{{ runningCount }} of {{ data.interfaces.length }} interfaces running</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-xs text-slate-500">Health</div>
                        <div v-if="!r.health.length" class="text-xs text-slate-400">No sensors on this board</div>
                        <div v-for="h in r.health" :key="h.name" class="flex justify-between gap-2 text-xs">
                            <span class="text-slate-500">{{ h.name }}</span>
                            <span class="font-medium text-slate-800">{{ h.value }} {{ h.unit }}</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <LiveTrafficChart
                        v-if="graphed"
                        :key="graphed"
                        url="/isp/router-interface-traffic"
                        :payload="{ id: router.id, interface: graphed }"
                        :series="[{ name: 'Rx (in)' }, { name: 'Tx (out)' }]"
                        :title="`Live traffic: ${graphed}`"
                        :offline-text="`The router has no interface named ${graphed} any more.`"
                        note="Rx = into the router on this port, Tx = out of it. On the WAN / uplink port, Rx is your download from upstream."
                    />
                    <div v-else class="py-4 text-center text-xs text-slate-500"><i class="bi bi-graph-up"></i> Pick an interface below (Graph) to see its live traffic. Pinned interfaces open here first.</div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
                        <h2 class="text-sm font-semibold text-slate-700">Interfaces</h2>
                        <div class="flex flex-wrap items-center gap-3 text-xs">
                            <input v-model="search" type="search" placeholder="Search name, type, comment" class="w-48 rounded-md border border-slate-300 px-2 py-1 text-xs" />
                            <label class="inline-flex items-center gap-1 text-slate-600"><input v-model="onlyRunning" type="checkbox" /> Only running</label>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                                    <th class="w-8 px-2 py-1.5"></th>
                                    <th class="px-2 py-1.5 font-medium">Interface</th>
                                    <th class="hidden px-2 py-1.5 font-medium sm:table-cell">Type</th>
                                    <th class="px-2 py-1.5 font-medium">Status</th>
                                    <th class="px-2 py-1.5 text-end font-medium">Rx</th>
                                    <th class="px-2 py-1.5 text-end font-medium">Tx</th>
                                    <th class="hidden px-2 py-1.5 text-end font-medium sm:table-cell">Link downs</th>
                                    <th class="px-2 py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in interfaces" :key="i.name" class="border-b border-slate-100" :class="graphed === i.name ? 'bg-slate-50' : ''">
                                    <td class="px-2 py-1.5">
                                        <button type="button" :disabled="savingPins" :title="pins.includes(i.name) ? 'Unpin' : 'Pin (WAN / uplink): listed first and graphed when the monitor opens'" class="text-slate-400 hover:text-slate-700" @click="togglePin(i.name)">
                                            <i class="bi" :class="pins.includes(i.name) ? 'bi-pin-angle-fill text-slate-700' : 'bi-pin-angle'"></i>
                                        </button>
                                    </td>
                                    <td class="px-2 py-1.5"><span class="whitespace-nowrap font-mono text-xs">{{ i.name }}</span><div v-if="i.comment" class="text-xs text-slate-500">{{ i.comment }}</div></td>
                                    <td class="hidden px-2 py-1.5 text-xs text-slate-600 sm:table-cell">{{ i.type }}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-xs" :class="status(i).cls"><i class="bi" :class="status(i).icon"></i> {{ status(i).label }}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-end text-xs text-slate-800">{{ rates[i.name] ? bps(rates[i.name].rx) : '…' }}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-end text-xs text-slate-800">{{ rates[i.name] ? bps(rates[i.name].tx) : '…' }}</td>
                                    <td class="hidden px-2 py-1.5 text-end text-xs text-slate-600 sm:table-cell" :title="i.last_link_down ? `Last down: ${i.last_link_down}` : ''">{{ i.link_downs }}</td>
                                    <td class="px-2 py-1.5 text-end">
                                        <button type="button" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs" :class="graphed === i.name ? 'border-slate-800' : ''" @click="graphed = graphed === i.name ? null : i.name"><i class="bi bi-graph-up"></i> Graph</button>
                                    </td>
                                </tr>
                                <tr v-if="!interfaces.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No interfaces match</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-1.5 text-[11px] text-slate-500">Rates are averages over the last {{ POLL_MS / 1000 }} seconds, from the byte counters. Customer sessions (&lt;pppoe-…&gt;) are not listed; see Online users.</div>
                </div>
            </template>
        </template>
    </div>
</template>
