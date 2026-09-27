<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { money, today, monthStart, fmtDate } from '../../lib/isp';
import { useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const showError = useApiError();
const filter = reactive({ from: monthStart(), to: today(), region_id: '' });
const data = ref(null);
const groupByRegion = ref(true);

async function load() {
    try {
        data.value = (await axios.post('/isp/get-company-dashboard', filter)).data;
    } catch (err) {
        showError(err);
    }
}
onMounted(load);

const exportUrl = computed(() => `/isp/company-dashboard-export?${new URLSearchParams({ from: filter.from, to: filter.to, region_id: filter.region_id })}`);
const hasRegions = computed(() => data.value?.regions.some((r) => r.region_id));
// branch rows grouped under their region's subtotal
const groups = computed(() => {
    if (!data.value) return [];
    if (!groupByRegion.value || !hasRegions.value) return [{ key: 'all', total: null, rows: data.value.branches }];
    return data.value.regions.map((r) => ({ key: r.region_id ?? 0, total: r, rows: data.value.branches.filter((b) => (b.region_id ?? null) === r.region_id) }));
});
const cards = computed(() => {
    const t = data.value?.total;
    if (!t) return [];
    return [
        { label: 'Active subscribers', value: t.subscribers, sub: `${t.new} new · ${t.churned} left (${t.churn_pct}%)` },
        { label: 'Revenue (billed, net of tax)', value: money(t.revenue), sub: `ARPU ${money(t.arpu)}` },
        { label: 'Collection', value: money(t.collection), sub: t.collection_pct !== null ? `${t.collection_pct}% of billed` : '' },
        { label: 'Profit', value: money(t.profit), sub: `Expenses ${money(t.expense)} · bandwidth ${money(t.bandwidth_cost)}`, tone: t.profit < 0 ? 'text-red-600' : 'text-emerald-600' },
        { label: 'Customer due', value: money(t.due), sub: `Overdue ${money(t.overdue)} · advance ${money(t.advance)}` },
    ];
});
const th = 'px-2 py-2 font-medium whitespace-nowrap';
const td = 'px-2 py-1.5 whitespace-nowrap';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div><label class="mb-1 block text-xs font-medium text-slate-600">From</label><input v-model="filter.from" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            <div><label class="mb-1 block text-xs font-medium text-slate-600">To</label><input v-model="filter.to" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            <div v-if="data?.region_options.length">
                <label class="mb-1 block text-xs font-medium text-slate-600">Region</label>
                <select v-model="filter.region_id" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">All</option>
                    <option v-for="r in data.region_options" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
            </div>
            <button type="button" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white" @click="load">Show</button>
            <label v-if="hasRegions" class="flex items-center gap-1 text-sm text-slate-600"><input v-model="groupByRegion" type="checkbox" /> Group by region</label>
            <span v-if="data" class="ms-auto text-xs text-slate-500">{{ data.scope }} · {{ fmtDate(data.from) }} – {{ fmtDate(data.to) }}</span>
            <a v-if="data" :href="exportUrl" class="text-slate-500 hover:text-brand-500" title="CSV"><i class="bi bi-download text-lg"></i></a>
        </div>

        <div v-if="data" class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div v-for="c in cards" :key="c.label" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">{{ c.label }}</div>
                <div class="text-lg font-semibold" :class="c.tone || 'text-slate-800'">{{ c.value }}</div>
                <div class="text-xs text-slate-400">{{ c.sub }}</div>
            </div>
        </div>

        <div v-if="data" class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-end text-slate-600">
                        <th :class="th" class="text-start">Branch</th>
                        <th :class="th">Active</th>
                        <th :class="th">New</th>
                        <th :class="th">Churn</th>
                        <th :class="th">ARPU</th>
                        <th :class="th">Revenue</th>
                        <th :class="th">Collection</th>
                        <th :class="th">Expenses</th>
                        <th :class="th">Bandwidth</th>
                        <th :class="th">Profit</th>
                        <th :class="th">Due</th>
                        <th :class="th">Overdue</th>
                    </tr>
                </thead>
                <tbody v-for="g in groups" :key="g.key">
                    <tr v-if="g.total" class="border-b border-slate-200 bg-slate-100 text-end font-semibold text-slate-700">
                        <td :class="td" class="text-start">{{ g.total.region || 'No region' }} <span class="font-normal text-slate-500">({{ g.total.branches }})</span></td>
                        <td :class="td">{{ g.total.subscribers }}</td>
                        <td :class="td">{{ g.total.new }}</td>
                        <td :class="td">{{ g.total.churned }} · {{ g.total.churn_pct }}%</td>
                        <td :class="td">{{ money(g.total.arpu) }}</td>
                        <td :class="td">{{ money(g.total.revenue) }}</td>
                        <td :class="td">{{ money(g.total.collection) }}</td>
                        <td :class="td">{{ money(g.total.expense) }}</td>
                        <td :class="td">{{ money(g.total.bandwidth_cost) }}</td>
                        <td :class="[td, g.total.profit < 0 ? 'text-red-600' : '']">{{ money(g.total.profit) }}</td>
                        <td :class="td">{{ money(g.total.due) }}</td>
                        <td :class="td">{{ money(g.total.overdue) }}</td>
                    </tr>
                    <tr v-for="b in g.rows" :key="b.branch_id" class="border-b border-slate-100 text-end">
                        <td :class="td" class="text-start" :style="g.total ? 'padding-inline-start:1.25rem' : ''">{{ b.branch }}</td>
                        <td :class="td">{{ b.subscribers }}<span v-if="b.suspended" class="text-xs text-amber-600"> +{{ b.suspended }} susp.</span></td>
                        <td :class="td">{{ b.new }}</td>
                        <td :class="td">{{ b.churned }} · {{ b.churn_pct }}%</td>
                        <td :class="td">{{ money(b.arpu) }}</td>
                        <td :class="td">{{ money(b.revenue) }}</td>
                        <td :class="td">{{ money(b.collection) }}<span v-if="b.collection_pct !== null" class="text-xs text-slate-400"> {{ b.collection_pct }}%</span></td>
                        <td :class="td">{{ money(b.expense) }}</td>
                        <td :class="td">{{ money(b.bandwidth_cost) }}</td>
                        <td :class="[td, b.profit < 0 ? 'text-red-600' : '']">{{ money(b.profit) }}</td>
                        <td :class="td">{{ money(b.due) }}</td>
                        <td :class="td">{{ money(b.overdue) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 bg-slate-50 text-end font-semibold">
                        <td :class="td" class="text-start">Total ({{ data.total.branches }} branches)</td>
                        <td :class="td">{{ data.total.subscribers }}</td>
                        <td :class="td">{{ data.total.new }}</td>
                        <td :class="td">{{ data.total.churned }} · {{ data.total.churn_pct }}%</td>
                        <td :class="td">{{ money(data.total.arpu) }}</td>
                        <td :class="td">{{ money(data.total.revenue) }}</td>
                        <td :class="td">{{ money(data.total.collection) }}</td>
                        <td :class="td">{{ money(data.total.expense) }}</td>
                        <td :class="td">{{ money(data.total.bandwidth_cost) }}</td>
                        <td :class="td">{{ money(data.total.profit) }}</td>
                        <td :class="td">{{ money(data.total.due) }}</td>
                        <td :class="td">{{ money(data.total.overdue) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-xs text-slate-500">
            Revenue = invoices billed in the period, net of tax. Profit = revenue + other income − expenses (Accounts) − upstream bandwidth cost (monthly cost spread per day).
            Churn = lines terminated in the period against active + suspended + terminated. Due, overdue and advance are as of now.
        </p>
    </div>
</template>
