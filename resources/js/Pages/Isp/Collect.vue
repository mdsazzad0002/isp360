<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError, money, fmtDate } from '../../lib/isp';

// A collector's phone page: who owes in my areas, take cash (receipt SMS goes by itself), note a
// visit, and hand the cash to the office at the end of the day.
defineOptions({ layout: AppLayout });
const props = defineProps({ outcomes: Object });
const toast = useToast();
const showError = useApiError();

const data = ref({ customers: [], areas: [], cash: {}, today: [] });
const filter = reactive({ area_id: '', search: '' });
const open = ref(null); // customer id being worked
const form = reactive({ amount: '', note: '', outcome: 'not_home', busy: false });
const hand = reactive({ open: false, amount: '', note: '' });
let timer;

const load = () => axios.post('/isp/get-collect-route', { ...filter }).then((r) => (data.value = r.data));
onMounted(load);
const onSearch = () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
};

function pick(c) {
    open.value = open.value === c.id ? null : c.id;
    Object.assign(form, { amount: Number(c.due) || '', note: '', outcome: 'not_home', busy: false });
}
// best effort: where the visit happened (the page works without it)
function position() {
    return new Promise((resolve) => {
        if (!navigator.geolocation) return resolve({});
        navigator.geolocation.getCurrentPosition((p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }), () => resolve({}), { timeout: 4000, maximumAge: 60000 });
    });
}
async function send(url, payload) {
    form.busy = true;
    try {
        const res = await axios.post(url, { ...payload, ...(await position()) });
        toast.success(res.data.message);
        open.value = null;
        load();
    } catch (err) {
        showError(err);
    } finally {
        form.busy = false;
    }
}
const collect = (c) => send('/isp/collect-pay', { customer_id: c.id, amount: form.amount, note: form.note });
const visit = (c) => send('/isp/collect-visit', { customer_id: c.id, outcome: form.outcome, note: form.note });
async function handover() {
    try {
        toast.success((await axios.post('/isp/collect-handover', { amount: hand.amount, note: hand.note })).data.message);
        hand.open = false;
        load();
    } catch (err) {
        showError(err);
    }
}
const VISIT_CLASS = { paid: 'bg-emerald-100 text-emerald-700', not_home: 'bg-slate-100 text-slate-600', promised: 'bg-amber-100 text-amber-700', refused: 'bg-red-100 text-red-700', other: 'bg-slate-100 text-slate-600' };
const input = 'w-full rounded-md border border-slate-300 px-2 py-2 text-base';
</script>

<template>
    <div class="mx-auto max-w-xl space-y-3 p-3">
        <div class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm"><div class="text-[11px] text-slate-500">Today</div><div class="font-semibold text-emerald-700">{{ money(data.cash.today) }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm"><div class="text-[11px] text-slate-500">Cash in hand</div><div class="font-semibold">{{ money(data.cash.in_hand) }}</div></div>
            <button type="button" class="rounded-lg border border-brand-300 bg-brand-50 p-2 text-brand-700 shadow-sm" @click="hand.open = !hand.open; hand.amount = Math.max(0, (data.cash.in_hand || 0) - (data.cash.pending || 0))">
                <div class="text-[11px]">Hand over</div><div class="text-xs">{{ data.cash.pending ? `${money(data.cash.pending)} waiting` : 'to office' }}</div>
            </button>
        </div>
        <form v-if="hand.open" class="space-y-2 rounded-lg border border-brand-200 bg-white p-3 shadow-sm" @submit.prevent="handover">
            <label class="block text-sm">Cash you give the office <input v-model="hand.amount" required type="number" min="0.01" step="0.01" :class="input" /></label>
            <input v-model="hand.note" placeholder="Note (optional)" :class="input" />
            <button type="submit" class="w-full rounded-md bg-brand-500 py-2 text-white">Record handover</button>
            <p class="text-xs text-slate-500">It stays in your hand until the office counts it and confirms.</p>
        </form>

        <div class="flex gap-2">
            <select v-model="filter.area_id" class="rounded-md border border-slate-300 px-2 py-2 text-sm" @change="load">
                <option value="">All my areas</option>
                <option v-for="a in data.areas" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
            <input v-model="filter.search" placeholder="Name, phone, address" class="flex-1 rounded-md border border-slate-300 px-2 py-2 text-sm" @input="onSearch" />
        </div>
        <div v-if="!data.areas.length" class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No areas are given to you yet. Ask the office (Collectors page).</div>

        <div v-for="c in data.customers" :key="c.id" class="rounded-lg border border-slate-200 bg-white shadow-sm" :class="{ 'opacity-60': c.visited_today && open !== c.id }">
            <button type="button" class="block w-full p-3 text-start" @click="pick(c)">
                <div class="flex items-center justify-between">
                    <b>{{ c.name }}</b>
                    <span class="font-semibold text-red-600">{{ money(c.due) }}</span>
                </div>
                <div class="text-xs text-slate-500">{{ c.code }} · {{ c.area }}<span v-if="c.oldest_due"> · due since {{ fmtDate(c.oldest_due) }}</span></div>
                <div v-if="c.address" class="text-xs text-slate-500">{{ c.address }}</div>
                <span v-if="c.visited_today" class="mt-1 inline-block rounded px-1.5 text-xs" :class="VISIT_CLASS[c.visited_today]">{{ outcomes[c.visited_today] }} today</span>
            </button>
            <div v-if="open === c.id" class="space-y-2 border-t border-slate-100 p-3">
                <a v-if="c.phone" :href="`tel:${c.phone}`" class="inline-flex items-center gap-1 text-brand-600"><i class="bi bi-telephone"></i> {{ c.phone }}</a>
                <div class="flex gap-2">
                    <input v-model="form.amount" type="number" min="0.01" step="0.01" placeholder="Amount" :class="input" />
                    <button type="button" :disabled="form.busy || !form.amount" class="whitespace-nowrap rounded-md bg-emerald-600 px-4 text-white disabled:opacity-50" @click="collect(c)">{{ form.busy ? '…' : 'Take cash' }}</button>
                </div>
                <input v-model="form.note" placeholder="Note (optional)" :class="input" />
                <div class="flex gap-2">
                    <select v-model="form.outcome" class="flex-1 rounded-md border border-slate-300 px-2 py-2 text-sm">
                        <option v-for="(l, k) in outcomes" v-show="k !== 'paid'" :key="k" :value="k">{{ l }}</option>
                    </select>
                    <button type="button" :disabled="form.busy" class="rounded-md border border-slate-300 px-3 text-sm" @click="visit(c)">Note visit</button>
                </div>
                <p class="text-xs text-slate-500">The customer gets the receipt by SMS.</p>
            </div>
        </div>
        <div v-if="data.areas.length && !data.customers.length" class="p-6 text-center text-sm text-slate-400">Nobody owes in your areas</div>

        <div v-if="data.today.length" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">Today's visits</h2>
            <div v-for="v in data.today" :key="v.id" class="flex justify-between border-b border-slate-100 py-1 text-sm last:border-0">
                <span>{{ v.customer?.name }}</span>
                <span class="rounded px-1.5 text-xs" :class="VISIT_CLASS[v.outcome]">{{ outcomes[v.outcome] }}</span>
            </div>
        </div>
    </div>
</template>
