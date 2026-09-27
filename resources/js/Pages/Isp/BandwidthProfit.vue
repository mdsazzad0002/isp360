<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ProfitChart from '../../Components/Isp/ProfitChart.vue';
import { money, useApiError, fmtMoney, today } from '../../lib/isp';

// Internet revenue billed vs the bandwidth bill, month by month, with profit merged in.
defineOptions({ layout: AppLayout });
const showError = useApiError();

const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const now = new Date(today() + 'T00:00:00'); // the company's date, not the browser's
const filter = reactive({ from: ym(new Date(now.getFullYear(), now.getMonth() - 11, 1)), to: ym(now) });
const data = ref(null);

function load() {
    axios.post('/isp/get-bandwidth-profit', { ...filter }).then((r) => (data.value = r.data)).catch(showError);
}
onMounted(load);
</script>

<template>
    <div class="p-4">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <h1 class="text-base font-semibold text-slate-800">Bandwidth Profit</h1>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                    <input v-model="filter.from" type="month" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="load" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <input v-model="filter.to" type="month" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="load" />
                </div>
                <Link href="/isp/bandwidth-usage" class="pb-1.5 text-sm text-brand-600 hover:underline">Bought vs sold</Link>
            </div>
        </div>

        <div v-if="!data" class="p-6 text-center text-slate-400">Loading…</div>
        <template v-else>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Revenue billed</div>
                    <div class="text-xl font-semibold text-slate-800">{{ fmtMoney(data.totals.revenue) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Bandwidth cost</div>
                    <div class="text-xl font-semibold text-slate-800">{{ fmtMoney(data.totals.cost) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Profit</div>
                    <div class="text-xl font-semibold" :class="data.totals.profit < 0 ? 'text-red-600' : 'text-slate-800'">{{ fmtMoney(data.totals.profit) }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Margin</div>
                    <div class="text-xl font-semibold text-slate-800">{{ data.totals.margin !== null ? `${data.totals.margin}%` : '—' }}</div>
                </div>
            </div>

            <div class="mt-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <ProfitChart :rows="data.months" />
            </div>

            <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                                <th class="px-3 py-2 font-medium">Month</th>
                                <th class="px-3 py-2 text-end font-medium">Bought Mbps</th>
                                <th class="px-3 py-2 text-end font-medium">Revenue</th>
                                <th class="px-3 py-2 text-end font-medium">Bandwidth cost</th>
                                <th class="px-3 py-2 text-end font-medium">Profit</th>
                                <th class="px-3 py-2 text-end font-medium">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in data.months" :key="m.month" class="border-b border-slate-100">
                                <td class="px-3 py-2">{{ m.label }}</td>
                                <td class="px-3 py-2 text-end">{{ money(m.purchased_mbps) }}</td>
                                <td class="px-3 py-2 text-end">{{ money(m.revenue) }}</td>
                                <td class="px-3 py-2 text-end">{{ money(m.cost) }}</td>
                                <td class="px-3 py-2 text-end font-medium" :class="m.profit < 0 ? 'text-red-600' : 'text-slate-800'">{{ money(m.profit) }}</td>
                                <td class="px-3 py-2 text-end text-slate-500">{{ m.margin !== null ? `${m.margin}%` : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Revenue = internet bills issued in the month (void bills excluded; for a reseller's package only the company's share). A multi-month bill counts in the month it was issued. Cost = each bandwidth purchase's monthly cost, prorated by the days it ran in the month.</p>
        </template>
    </div>
</template>
