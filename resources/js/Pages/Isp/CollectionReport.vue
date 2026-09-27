<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import BarList from '../../Components/Isp/BarList.vue';
import { money, fmtDate, label, today, monthStart, escapeHtml as e, fmtMoney } from '../../lib/isp';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });
const pageProps = usePage();
const filter = reactive({ dateFrom: monthStart(), dateTo: today() });
const data = ref(null);

function load() {
    axios.post('/isp/get-collection-report', filter).then((r) => (data.value = r.data));
}

function table(title, rows, fmtLabel = (x) => x) {
    const td = 'border:1px solid #cbd5e1;padding:3px 5px;';
    return `<h3 style="font-size:12px;margin:10px 0 4px;">${title}</h3><table style="width:100%;border-collapse:collapse;font-size:11px;"><tr><th style="${td}text-align:left;">Name</th><th style="${td}">Count</th><th style="${td}">Amount</th></tr>${rows.map((r) => `<tr><td style="${td}">${e(fmtLabel(r.label))}</td><td style="${td}text-align:right;">${r.count}</td><td style="${td}text-align:right;">${money(r.amount)}</td></tr>`).join('')}</table>`;
}
function print() {
    const d = data.value;
    const body = `<p style="font-size:12px;">${fmtDate(d.from)} – ${fmtDate(d.to)} · ${d.count} payments · Net ${fmtMoney(d.total)} · Reversed ${fmtMoney(d.reversed)}</p>` +
        table('By method', d.by_method, label) + table('By collector', d.by_collector) + table('By area', d.by_area) + table('By day', d.by_day, fmtDate);
    printDocument('Collection Report', body, pageProps.props.company);
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div><label class="mb-1 block text-xs font-medium text-slate-600">From</label><input v-model="filter.dateFrom" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            <div><label class="mb-1 block text-xs font-medium text-slate-600">To</label><input v-model="filter.dateTo" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            <button type="button" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white" @click="load">Show</button>
            <button v-if="data" type="button" class="ml-auto text-slate-500 hover:text-brand-500" title="Print" @click="print"><i class="bi bi-printer text-lg"></i></button>
        </div>
        <template v-if="data">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Net collection</div><div class="text-xl font-semibold text-slate-800">{{ money(data.total) }}</div></div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Payments</div><div class="text-xl font-semibold text-slate-800">{{ data.count }}</div></div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Reversed (excluded)</div><div class="text-xl font-semibold text-slate-800">{{ money(data.reversed) }}</div></div>
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"><h2 class="mb-3 text-sm font-semibold text-slate-700">By method</h2><BarList :rows="data.by_method.map((r) => ({ label: `${label(r.label)} (${r.count})`, value: Number(r.amount) }))" is-money /></div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"><h2 class="mb-3 text-sm font-semibold text-slate-700">By collector</h2><BarList :rows="data.by_collector.map((r) => ({ label: `${r.label} (${r.count})`, value: Number(r.amount) }))" is-money /></div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"><h2 class="mb-3 text-sm font-semibold text-slate-700">By area</h2><BarList :rows="data.by_area.map((r) => ({ label: `${r.label} (${r.count})`, value: Number(r.amount) }))" is-money /></div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <h2 class="mb-2 text-sm font-semibold text-slate-700">Day-wise</h2>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600"><th class="px-2 py-2 font-medium">Date</th><th class="px-2 py-2 text-right font-medium">Payments</th><th class="px-2 py-2 text-right font-medium">Amount</th></tr></thead>
                    <tbody>
                        <tr v-for="d in data.by_day" :key="d.label" class="border-b border-slate-100"><td class="px-2 py-1.5">{{ fmtDate(d.label) }}</td><td class="px-2 py-1.5 text-right">{{ d.count }}</td><td class="px-2 py-1.5 text-right font-medium">{{ money(d.amount) }}</td></tr>
                        <tr v-if="!data.by_day.length"><td colspan="3" class="px-2 py-6 text-center text-slate-400">No collections in this range</td></tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
