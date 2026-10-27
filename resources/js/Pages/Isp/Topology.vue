<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { fmtDateTime, label } from '../../lib/isp';

// OLT → PON port → box → ONU → customer, with what is down. Built from the OLT polls and the
// ONU ↔ connection links; nothing to draw by hand.
defineOptions({ layout: AppLayout });
const props = defineProps({ weakRx: Number });

const olts = ref([]);
const tree = ref(null);
const current = ref(null);
const openPorts = reactive({});
const search = ref('');

onMounted(async () => {
    olts.value = (await axios.post('/isp/get-topology')).data;
    if (olts.value.length) pick(olts.value[0]);
});
async function pick(o) {
    current.value = o.id;
    tree.value = null;
    tree.value = (await axios.post('/isp/get-topology', { olt_id: o.id })).data;
    for (const p of tree.value.ports) openPorts[p.name] = p.down; // down ports open by themselves
}
const q = computed(() => search.value.trim().toLowerCase());
const matches = (u) => !q.value || [u.serial, u.name, u.customer?.name, u.customer?.code, u.customer?.phone, u.connection?.code].some((v) => String(v || '').toLowerCase().includes(q.value));
const ports = computed(() => (tree.value?.ports || []).map((p) => ({ ...p, boxes: p.boxes.map((b) => ({ ...b, onus: b.onus.filter(matches) })).filter((b) => b.onus.length) })).filter((p) => !q.value || p.boxes.length));

const dot = (s) => ({ online: 'bg-emerald-500', offline: 'bg-slate-400', los: 'bg-red-500', dying_gasp: 'bg-red-500', auth_failed: 'bg-amber-500' })[s] || 'bg-slate-300';
const rxClass = (v) => (v === null ? 'text-slate-400' : v < props.weakRx ? 'text-red-600' : 'text-emerald-700');
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="me-2 text-base font-semibold text-slate-800">Network Topology</h1>
            <button v-for="o in olts" :key="o.id" type="button" class="rounded-lg border px-3 py-1.5 text-start text-sm" :class="current === o.id ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white'" @click="pick(o)">
                <span class="me-1 inline-block h-2 w-2 rounded-full" :class="o.down ? 'bg-red-500' : o.ports_down ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                <b>{{ o.name }}</b>
                <span class="text-xs text-slate-500"> {{ o.online }}/{{ o.total }} online<span v-if="o.ports_down" class="text-red-600"> · {{ o.ports_down }} port(s) down</span></span>
            </button>
            <input v-model="search" placeholder="Find customer, serial, code…" class="ms-auto rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
        </div>
        <div v-if="!olts.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-400">Add an OLT under Network → OLT &amp; ONU to see the topology.</div>

        <div v-if="tree" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 flex items-center gap-2 text-sm">
                <i class="bi bi-hdd-rack text-lg"></i>
                <b>{{ tree.olt.name }}</b>
                <span class="font-mono text-xs text-slate-500">{{ tree.olt.host }}</span>
                <span v-if="tree.olt.location" class="text-xs text-slate-500">· {{ tree.olt.location }}</span>
                <span v-if="tree.down" class="rounded bg-red-100 px-2 text-xs text-red-700">unreachable</span>
                <span class="ms-auto text-xs text-slate-400">read {{ fmtDateTime(tree.olt.last_polled_at) || 'never' }}</span>
            </div>
            <div class="space-y-1 border-s-2 border-slate-200 ps-3">
                <div v-for="p in ports" :key="p.name">
                    <button type="button" class="flex w-full items-center gap-2 rounded px-1 py-1 text-start text-sm hover:bg-slate-50" @click="openPorts[p.name] = !openPorts[p.name]">
                        <i class="bi" :class="openPorts[p.name] || q ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                        <span class="inline-block h-2 w-2 rounded-full" :class="p.down ? 'bg-red-500' : p.online === p.total ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                        <span class="font-mono">{{ p.name }}</span>
                        <span class="text-xs text-slate-500">{{ p.online }}/{{ p.total }} online</span>
                        <span v-if="p.down" class="rounded bg-red-100 px-1.5 text-xs text-red-700">PON down</span>
                    </button>
                    <div v-if="openPorts[p.name] || q" class="ms-5 space-y-2 border-s border-dashed border-slate-300 py-1 ps-3">
                        <div v-for="b in p.boxes" :key="b.id ?? 'none'">
                            <div class="text-xs font-medium text-slate-600"><i class="bi bi-box"></i> {{ b.name }} <span class="text-slate-400">({{ b.onus.length }})</span></div>
                            <div class="ms-4 grid gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div v-for="u in b.onus" :key="u.id" class="flex items-center gap-2 py-0.5 text-xs">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="dot(u.status)" :title="label(u.status)"></span>
                                    <span class="font-mono text-slate-500">:{{ u.onu_id ?? '?' }}</span>
                                    <Link v-if="u.customer" :href="`/isp/customer/${u.customer.id}`" class="truncate text-brand-600 hover:underline">{{ u.customer.name }}</Link>
                                    <span v-else class="truncate text-slate-400">{{ u.name || u.serial || 'not linked' }}</span>
                                    <span class="ms-auto font-mono" :class="rxClass(u.rx_power)">{{ u.rx_power ?? '' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="!ports.length" class="py-3 text-sm text-slate-400">{{ q ? 'No match' : 'No ONUs on this OLT yet' }}</div>
            </div>
        </div>
    </div>
</template>
