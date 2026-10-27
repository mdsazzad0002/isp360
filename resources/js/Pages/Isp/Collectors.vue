<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError, money, fmtDateTime, today, promptReason, label } from '../../lib/isp';

// The office side of collection: who covers which areas, the cash each one holds, handovers to
// count and confirm, and the day's visits.
defineOptions({ layout: AppLayout });
const props = defineProps({ staff: Array, areas: Array, outcomes: Object });
const toast = useToast();
const showError = useApiError();

const collectors = ref([]);
const handovers = ref([]);
const visits = ref([]);
const add = reactive({ user_id: '', area_ids: [] });
const editing = ref(null); // collector id whose areas are open
const visitFilter = reactive({ user_id: '', date: today() });

const load = () => axios.post('/isp/get-collectors').then((r) => (collectors.value = r.data));
const loadHandovers = () => axios.post('/isp/get-cash-handovers', { status: 'pending' }).then((r) => (handovers.value = r.data.data));
const loadVisits = () => axios.post('/isp/get-collection-visits', { ...visitFilter }).then((r) => (visits.value = r.data.data));
onMounted(() => {
    load();
    loadHandovers();
    loadVisits();
});

async function saveAreas(userId, areaIds) {
    try {
        toast.success((await axios.post('/isp/collector-areas', { user_id: userId, area_ids: areaIds })).data.message);
        editing.value = null;
        Object.assign(add, { user_id: '', area_ids: [] });
        load();
    } catch (err) {
        showError(err);
    }
}
async function decide(h, confirm) {
    const reason = confirm ? null : await promptReason('Why is this handover rejected?');
    if (!confirm && !reason) return;
    try {
        toast.success((await axios.post('/isp/cash-handover-decide', { id: h.id, confirm, reason })).data.message);
        loadHandovers();
        load();
    } catch (err) {
        showError(err);
    }
}
const areaName = (id) => props.areas.find((a) => a.id === id)?.name || id;
</script>

<template>
    <div class="space-y-3 p-4">
        <div v-if="handovers.length" class="rounded-lg border border-amber-300 bg-amber-50 p-3 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold text-amber-900">Cash to count and confirm</h2>
            <div v-for="h in handovers" :key="h.id" class="flex flex-wrap items-center gap-2 border-b border-amber-200 py-1.5 text-sm last:border-0">
                <b>{{ h.collector?.name }}</b>
                <span class="text-lg font-semibold">{{ money(h.amount) }}</span>
                <span class="text-xs text-slate-500">{{ fmtDateTime(h.created_at) }}<span v-if="h.note"> · {{ h.note }}</span></span>
                <button type="button" class="ms-auto rounded-md bg-emerald-600 px-3 py-1 text-xs text-white" @click="decide(h, true)">Received</button>
                <button type="button" class="rounded-md border border-red-300 px-3 py-1 text-xs text-red-600" @click="decide(h, false)">Reject</button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-3">
                <h1 class="text-base font-semibold text-slate-800">Collectors</h1>
                <p class="text-xs text-slate-500">A collector (permission "Collector") sees the customers who owe in their areas on the Collect page, takes cash there (the customer gets an SMS receipt), and hands the cash over here.</p>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Collector</th>
                        <th class="px-3 py-2 font-medium">Areas</th>
                        <th class="px-3 py-2 text-end font-medium">Today</th>
                        <th class="px-3 py-2 text-end font-medium">Cash in hand</th>
                        <th class="px-3 py-2 text-end font-medium">Waiting</th>
                        <th class="px-3 py-2 text-end font-medium">Visits today</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in collectors" :key="c.id" class="border-b border-slate-100 align-top">
                        <td class="px-3 py-2 font-medium">{{ c.name }}</td>
                        <td class="px-3 py-2">
                            <template v-if="editing === c.id">
                                <select v-model="c.area_ids" multiple class="h-28 w-56 rounded-md border border-slate-300 text-xs"><option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                                <div class="mt-1 flex gap-2"><button type="button" class="rounded bg-brand-500 px-2 py-0.5 text-xs text-white" @click="saveAreas(c.id, c.area_ids)">Save</button><button type="button" class="text-xs text-slate-500" @click="editing = null; load()">Cancel</button></div>
                            </template>
                            <button v-else type="button" class="text-start text-xs hover:underline" @click="editing = c.id">{{ c.area_ids.map(areaName).join(', ') || '— none —' }} <i class="bi bi-pen text-brand-500"></i></button>
                        </td>
                        <td class="px-3 py-2 text-end">{{ money(c.cash.today) }}</td>
                        <td class="px-3 py-2 text-end font-semibold" :class="c.cash.in_hand > 0 ? 'text-amber-700' : ''">{{ money(c.cash.in_hand) }}</td>
                        <td class="px-3 py-2 text-end text-slate-500">{{ c.cash.pending ? money(c.cash.pending) : '' }}</td>
                        <td class="px-3 py-2 text-end">{{ c.visits_today }}</td>
                    </tr>
                    <tr v-if="!collectors.length"><td colspan="6" class="px-3 py-4 text-center text-slate-400">No collectors yet</td></tr>
                </tbody>
            </table>
            <form class="flex flex-wrap items-end gap-2 border-t border-slate-200 p-3" @submit.prevent="saveAreas(add.user_id, add.area_ids)">
                <label class="text-xs text-slate-600">Add a collector
                    <select v-model="add.user_id" required class="block rounded-md border border-slate-300 px-2 py-1 text-sm"><option value="">— staff —</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </label>
                <label class="text-xs text-slate-600">Areas (hold Ctrl for several)
                    <select v-model="add.area_ids" multiple required class="block h-20 w-56 rounded-md border border-slate-300 text-xs"><option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                </label>
                <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Save</button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <h2 class="me-auto text-sm font-semibold text-slate-700">Visits</h2>
                <select v-model="visitFilter.user_id" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="loadVisits"><option value="">All collectors</option><option v-for="c in collectors" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                <input v-model="visitFilter.date" type="date" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="loadVisits" />
            </div>
            <table class="w-full text-xs">
                <tr v-for="v in visits" :key="v.id" class="border-b border-slate-100">
                    <td class="py-1 text-slate-500">{{ fmtDateTime(v.created_at).slice(-5) }}</td>
                    <td class="py-1">{{ v.user?.name }}</td>
                    <td class="py-1">{{ v.customer?.name }} <span class="text-slate-400">{{ v.customer?.code }}</span></td>
                    <td class="py-1">{{ outcomes[v.outcome] || label(v.outcome) }}<span v-if="v.note" class="text-slate-500"> · {{ v.note }}</span></td>
                    <td class="py-1 text-end"><a v-if="v.latitude" :href="`https://www.google.com/maps?q=${v.latitude},${v.longitude}`" target="_blank" rel="noopener" class="text-brand-600"><i class="bi bi-geo-alt"></i> map</a></td>
                </tr>
                <tr v-if="!visits.length"><td class="py-3 text-center text-slate-400">No visits that day</td></tr>
            </table>
        </div>
    </div>
</template>
