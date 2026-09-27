<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { money, fmtDate, today, monthStart, escapeHtml as e, fmtMoney, useApiError } from '../../lib/isp';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });
const pageProps = usePage();
const showError = useApiError();
const filter = reactive({ dateFrom: monthStart(), dateTo: today() });
const data = ref(null);

function load() {
    axios.post('/isp/get-tax-report', filter).then((r) => (data.value = r.data)).catch(showError);
}

const COLUMNS = [
    ['sales', 'Tax on invoices'],
    ['voided', 'Less: voided'],
    ['debit_notes', 'Add: debit notes'],
    ['credit_notes', 'Less: credit notes'],
    ['credits', 'Less: package-change credits'],
    ['net', 'Net tax'],
];

function print() {
    const d = data.value;
    const td = 'border:1px solid #cbd5e1;padding:3px 5px;';
    const rates = d.rates.map((r) => `<tr><td style="${td}">${e(r.name)} ${r.rate}%</td><td style="${td}text-align:right;">${money(r.taxable)}</td><td style="${td}text-align:right;">${money(r.tax)}</td></tr>`).join('');
    const head = COLUMNS.map(([, l]) => `<th style="${td}">${l}</th>`).join('');
    const line = (name, r) => `<tr><td style="${td}">${e(name)}</td>${COLUMNS.map(([k]) => `<td style="${td}text-align:right;">${money(r[k])}</td>`).join('')}</tr>`;
    const body = `<p style="font-size:12px;">${fmtDate(d.from)} – ${fmtDate(d.to)}${d.tax_number ? ` · ${e(d.label)} No: ${e(d.tax_number)}` : ''} · Net ${e(d.label)}: <strong>${fmtMoney(d.totals.net)}</strong></p>
        <h3 style="font-size:12px;margin:10px 0 4px;">Invoices by rate</h3>
        <table style="width:100%;border-collapse:collapse;font-size:11px;"><tr><th style="${td}text-align:left;">Rate</th><th style="${td}">Taxable amount</th><th style="${td}">Tax</th></tr>${rates}</table>
        <h3 style="font-size:12px;margin:10px 0 4px;">By branch</h3>
        <table style="width:100%;border-collapse:collapse;font-size:11px;"><tr><th style="${td}text-align:left;">Branch</th>${head}</tr>${d.branches.map((b) => line(b.branch, b)).join('')}${line('Total', d.totals)}</table>`;
    printDocument(`${d.label} Report`, body, pageProps.props.company);
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div><label class="mb-1 block text-xs font-medium text-slate-600">From</label><input v-model="filter.dateFrom" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            <div><label class="mb-1 block text-xs font-medium text-slate-600">To</label><input v-model="filter.dateTo" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            <button type="button" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white" @click="load">Show</button>
            <span class="text-xs text-slate-500">Whole company, all branches.</span>
            <button v-if="data" type="button" class="ml-auto text-slate-500 hover:text-brand-500" title="Print" @click="print"><i class="bi bi-printer text-lg"></i></button>
        </div>
        <template v-if="data">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Net {{ data.label }} for the period</div><div class="text-xl font-semibold text-slate-800">{{ fmtMoney(data.totals.net) }}</div></div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">On invoices issued</div><div class="text-xl font-semibold text-slate-800">{{ fmtMoney(data.totals.sales) }}</div></div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Company {{ data.label }} number</div><div class="text-xl font-semibold text-slate-800">{{ data.tax_number || '—' }}</div></div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <h2 class="mb-2 text-sm font-semibold text-slate-700">Invoices by rate</h2>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600"><th class="px-2 py-2 font-medium">Rate</th><th class="px-2 py-2 text-right font-medium">Taxable amount</th><th class="px-2 py-2 text-right font-medium">Tax</th></tr></thead>
                    <tbody>
                        <tr v-for="r in data.rates" :key="`${r.name}${r.rate}`" class="border-b border-slate-100"><td class="px-2 py-1.5">{{ r.name }} {{ r.rate }}%</td><td class="px-2 py-1.5 text-right">{{ money(r.taxable) }}</td><td class="px-2 py-1.5 text-right font-medium">{{ money(r.tax) }}</td></tr>
                        <tr v-if="!data.rates.length"><td colspan="3" class="px-2 py-6 text-center text-slate-400">No taxed invoices in this range</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <h2 class="mb-2 text-sm font-semibold text-slate-700">By branch</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Branch</th>
                            <th v-for="[k, l] in COLUMNS" :key="k" class="px-2 py-2 text-right font-medium">{{ l }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in data.branches" :key="b.branch" class="border-b border-slate-100">
                            <td class="px-2 py-1.5">{{ b.branch }}</td>
                            <td v-for="[k] in COLUMNS" :key="k" class="px-2 py-1.5 text-right" :class="k === 'net' ? 'font-semibold' : ''">{{ money(b[k]) }}</td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="px-2 py-1.5">Total</td>
                            <td v-for="[k] in COLUMNS" :key="k" class="px-2 py-1.5 text-right">{{ money(data.totals[k]) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-slate-500">Invoices count on their invoice date, voids on the day they were voided, notes on their note date. Draft invoices never count.</p>
            </div>
        </template>
    </div>
</template>
