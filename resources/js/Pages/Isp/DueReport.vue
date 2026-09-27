<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { money, fmtDate, label, escapeHtml as e } from '../../lib/isp';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });
const pageProps = usePage();

const tab = ref('customers');
const rows = ref([]);
const totals = ref({});
const page = ref(1);
const lastPage = ref(1);
const zones = ref([]);
const areas = ref([]);
const boxes = ref([]);
const summary = ref([]);
const summaryGroup = ref('area');
const filter = reactive({ mode: 'due', search: '', zone: null, area: null, box: null, sortBy: 'balance', sortDir: 'desc' });
let timer = null;

function params(extra = {}) {
    return { mode: filter.mode, search: filter.search, zoneId: filter.zone?.id, areaId: filter.area?.id, boxId: filter.box?.id, sortBy: filter.sortBy, sortDir: filter.sortDir, ...extra };
}
function load() {
    axios.post('/isp/get-due-report', params({ page: page.value, per_page: 25 })).then((res) => {
        rows.value = res.data.page.data;
        lastPage.value = res.data.page.last_page;
        totals.value = res.data.totals;
    });
}
function reload() {
    page.value = 1;
    load();
}
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(reload, 300);
}
function sort(col) {
    filter.sortDir = filter.sortBy === col && filter.sortDir === 'desc' ? 'asc' : 'desc';
    filter.sortBy = col;
    reload();
}
function loadSummary() {
    axios.post('/isp/get-due-summary', { group: summaryGroup.value }).then((r) => (summary.value = r.data));
}

async function print() {
    const res = await axios.post('/isp/get-due-report', params({ all_rows: 1 }));
    const list = res.data.page.data;
    const td = 'border:1px solid #cbd5e1;padding:3px 5px;';
    const body = `<table style="width:100%;border-collapse:collapse;font-size:11px;">
        <thead><tr><th style="${td}">#</th><th style="${td}">Code</th><th style="${td}">Name</th><th style="${td}">Mobile</th><th style="${td}">Area / Box</th><th style="${td}">Oldest due</th><th style="${td}">Overdue</th><th style="${td}">Balance</th></tr></thead>
        <tbody>${list.map((r, i) => `<tr><td style="${td}">${i + 1}</td><td style="${td}">${e(r.code)}</td><td style="${td}">${e(r.name)}</td><td style="${td}">${e(r.phone)}</td><td style="${td}">${e(r.area_name || '')} ${e(r.box_name || '')}</td><td style="${td}">${fmtDate(r.oldest_due_date)}</td><td style="${td}text-align:right;">${money(r.overdue)}</td><td style="${td}text-align:right;">${money(r.balance)}</td></tr>`).join('')}
        <tr style="font-weight:bold;"><td style="${td}" colspan="6">Total (${list.length})</td><td style="${td}text-align:right;">${money(res.data.totals.overdue)}</td><td style="${td}text-align:right;">${money(Number(res.data.totals.due) + Number(res.data.totals.advance))}</td></tr></tbody></table>`;
    printDocument(`Customer ${label(filter.mode)} Report`, body, pageProps.props.company);
}

onMounted(() => {
    load();
    loadSummary();
    axios.post('/isp/get-zones').then((r) => (zones.value = r.data));
    axios.post('/get-area').then((r) => (areas.value = r.data));
    axios.post('/isp/get-boxes').then((r) => (boxes.value = r.data));
});
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Customers in list</div><div class="text-lg font-semibold text-slate-800">{{ totals.customers ?? 0 }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Total due</div><div class="text-lg font-semibold text-red-600">{{ money(totals.due) }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Overdue part</div><div class="text-lg font-semibold text-red-600">{{ money(totals.overdue) }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Advance held</div><div class="text-lg font-semibold text-emerald-700">{{ money(Math.abs(totals.advance || 0)) }}</div></div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex gap-1 border-b border-slate-200 px-2">
                <button v-for="t in [['customers', 'Customer-wise'], ['summary', 'Zone / Area / Box summary']]" :key="t[0]" type="button" class="border-b-2 px-3 py-2.5 text-sm" :class="tab === t[0] ? 'border-brand-500 font-medium text-brand-600' : 'border-transparent text-slate-500'" @click="tab = t[0]">{{ t[1] }}</button>
            </div>

            <div v-if="tab === 'customers'" class="p-3">
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Show</label>
                        <select v-model="filter.mode" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="due">Customers with due</option>
                            <option value="overdue">Overdue only</option>
                            <option value="advance">Advance / credit</option>
                            <option value="all">All customers</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input v-model="filter.search" @input="onSearch" placeholder="Name, phone, code" class="w-48 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="w-40"><label class="mb-1 block text-xs font-medium text-slate-600">Zone</label><SearchSelect :options="zones" v-model="filter.zone" label="name" placeholder="All" @update:model-value="reload" /></div>
                    <div class="w-40"><label class="mb-1 block text-xs font-medium text-slate-600">Area</label><SearchSelect :options="areas" v-model="filter.area" label="name" placeholder="All" @update:model-value="reload" /></div>
                    <div class="w-40"><label class="mb-1 block text-xs font-medium text-slate-600">Box</label><SearchSelect :options="boxes" v-model="filter.box" label="name" placeholder="All" @update:model-value="reload" /></div>
                    <button type="button" class="ml-auto text-slate-500 hover:text-brand-500" title="Print" @click="print"><i class="bi bi-printer text-lg"></i></button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="cursor-pointer px-2 py-2 font-medium" @click="sort('code')">Code</th>
                                <th class="cursor-pointer px-2 py-2 font-medium" @click="sort('name')">Customer</th>
                                <th class="cursor-pointer px-2 py-2 font-medium" @click="sort('area')">Zone / Area / Box</th>
                                <th class="px-2 py-2 font-medium">Connection</th>
                                <th class="cursor-pointer px-2 py-2 font-medium" @click="sort('oldest')">Oldest overdue</th>
                                <th class="cursor-pointer px-2 py-2 text-right font-medium" @click="sort('overdue')">Overdue</th>
                                <th class="cursor-pointer px-2 py-2 text-right font-medium" @click="sort('balance')">Balance <i v-if="filter.sortBy === 'balance'" :class="filter.sortDir === 'desc' ? 'bi bi-arrow-down' : 'bi bi-arrow-up'"></i></th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-2 py-2">{{ r.code }}</td>
                                <td class="px-2 py-2"><Link :href="`/isp/customer/${r.id}`" class="font-medium text-brand-600 hover:underline">{{ r.name }}</Link><div class="text-xs text-slate-400">{{ r.phone }}</div></td>
                                <td class="px-2 py-2 text-xs">{{ [r.zone_name, r.area_name, r.box_name].filter(Boolean).join(' → ') || '—' }}</td>
                                <td class="px-2 py-2"><StatusBadge v-for="s in (r.connection_status || '').split(',').filter(Boolean)" :key="s" :status="s" class="mr-1" /></td>
                                <td class="px-2 py-2">{{ fmtDate(r.oldest_due_date) || '—' }}</td>
                                <td class="px-2 py-2 text-right text-red-600">{{ Number(r.overdue) ? money(r.overdue) : '' }}</td>
                                <td class="px-2 py-2 text-right font-semibold" :class="Number(r.balance) > 0 ? 'text-red-600' : 'text-emerald-700'">{{ money(r.balance) }}</td>
                                <td class="px-2 py-2 text-right"><Link v-if="Number(r.balance) > 0" :href="`/isp/payments?customerId=${r.id}`" class="text-xs text-brand-600 hover:underline">Collect</Link></td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="8" class="px-2 py-6 text-center text-slate-400">Nothing to show</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
            </div>

            <div v-else class="p-3">
                <div class="mb-3 flex gap-3 text-sm">
                    <label v-for="g in ['zone', 'area', 'box']" :key="g" class="flex items-center gap-1"><input type="radio" :value="g" v-model="summaryGroup" @change="loadSummary" /> {{ label(g) }}-wise</label>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">{{ label(summaryGroup) }}</th>
                            <th class="px-2 py-2 text-right font-medium">Customers</th>
                            <th class="px-2 py-2 text-right font-medium">With due</th>
                            <th class="px-2 py-2 text-right font-medium">Due</th>
                            <th class="px-2 py-2 text-right font-medium">Overdue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in summary" :key="s.name" class="border-b border-slate-100">
                            <td class="px-2 py-2">{{ s.name }}</td>
                            <td class="px-2 py-2 text-right">{{ s.customers }}</td>
                            <td class="px-2 py-2 text-right">{{ s.due_customers }}</td>
                            <td class="px-2 py-2 text-right font-medium text-red-600">{{ money(s.due) }}</td>
                            <td class="px-2 py-2 text-right text-red-600">{{ money(s.overdue) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
