<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import WorkOrderDetail from '../../Components/Isp/WorkOrderDetail.vue';
import { fmtDateTime, label } from '../../lib/isp';

// A technician's phone page: today's and upcoming jobs, and the stock they carry.
defineOptions({ layout: AppLayout });
defineProps({ types: Object });

const status = ref('open');
const jobs = ref([]);
const held = ref([]);
const openId = ref(null);

const load = () => axios.post('/isp/get-work-orders', { mine: true, status: status.value, per_page: 50 }).then((r) => (jobs.value = r.data.page.data));
const loadHeld = () => axios.post('/isp/get-inventory-held').then((r) => (held.value = r.data)).catch(() => (held.value = []));
onMounted(() => {
    load();
    loadHeld();
});
const refresh = () => {
    load();
    loadHeld();
};
const isToday = (d) => d && new Date(d).toDateString() === new Date().toDateString();
const BORDER = { urgent: 'border-l-red-500', high: 'border-l-orange-400', normal: 'border-l-brand-500', low: 'border-l-slate-300' };
</script>

<template>
    <div class="mx-auto max-w-xl space-y-3 p-3">
        <div class="flex items-center gap-2">
            <h1 class="me-auto text-base font-semibold text-slate-800">My Jobs</h1>
            <button v-for="s in ['open', 'done']" :key="s" type="button" class="rounded-full border px-3 py-1 text-xs" :class="status === s ? 'border-brand-500 bg-brand-500 text-white' : 'border-slate-300 bg-white'" @click="status = s; load()">
                {{ s === 'open' ? 'To do' : 'Done' }}
            </button>
        </div>

        <button v-for="w in jobs" :key="w.id" type="button" class="block w-full rounded-lg border border-l-4 border-slate-200 bg-white p-3 text-start shadow-sm" :class="BORDER[w.priority]" @click="openId = w.id">
            <div class="flex items-center justify-between text-sm">
                <b>{{ types[w.type] }}</b>
                <span class="text-xs" :class="isToday(w.scheduled_at) ? 'font-semibold text-brand-600' : 'text-slate-500'">{{ w.scheduled_at ? (isToday(w.scheduled_at) ? 'Today ' + fmtDateTime(w.scheduled_at).slice(-5) : fmtDateTime(w.scheduled_at)) : 'No time set' }}</span>
            </div>
            <div class="text-sm">{{ w.customer?.name }} <span class="text-xs text-slate-400">{{ w.customer?.phone }}</span></div>
            <div class="text-xs text-slate-500">{{ w.address }}</div>
            <div class="mt-1 flex items-center gap-2 text-xs">
                <span class="rounded bg-slate-100 px-1.5">{{ label(w.status) }}</span>
                <span class="text-slate-400">{{ w.wo_no }}</span>
                <span v-if="w.priority !== 'normal'" class="text-red-600">{{ label(w.priority) }}</span>
            </div>
        </button>
        <div v-if="!jobs.length" class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400">{{ status === 'open' ? 'No jobs for you right now' : 'Nothing done yet' }}</div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700"><i class="bi bi-box-seam"></i> Stock with me</h2>
            <div v-for="h in held" :key="h.item.id" class="border-b border-slate-100 py-1 text-sm last:border-0">
                {{ h.item.name }}: <b>{{ h.qty }} {{ h.item.unit === 'm' ? 'm' : '' }}</b>
                <div v-if="h.serials.length" class="font-mono text-xs text-slate-500">{{ h.serials.join(', ') }}</div>
            </div>
            <div v-if="!held.length" class="text-xs text-slate-400">Nothing issued to you</div>
        </div>

        <WorkOrderDetail :id="openId" @close="openId = null" @changed="refresh" />
    </div>
</template>
