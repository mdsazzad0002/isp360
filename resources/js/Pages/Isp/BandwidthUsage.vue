<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { money } from '../../lib/isp';

// Bandwidth bought (running purchases) against bandwidth sold on active connections, today.
defineOptions({ layout: AppLayout });

const data = ref(null);
const soldPct = computed(() => (data.value?.purchased_mbps ? Math.min(100, (data.value.sold_mbps / data.value.purchased_mbps) * 100) : 0));
const oversold = computed(() => data.value && data.value.purchased_mbps > 0 && data.value.sold_mbps > data.value.purchased_mbps);

onMounted(() => axios.post('/isp/get-bandwidth-usage').then((r) => (data.value = r.data)));
</script>

<template>
    <div class="p-4">
        <div class="mb-3 flex items-center justify-between">
            <h1 class="text-base font-semibold text-slate-800">Bandwidth Usage — bought vs sold</h1>
            <Link href="/isp/bandwidth" class="text-sm text-brand-600 hover:underline"><i class="bi bi-cloud-download"></i> Bandwidth purchases</Link>
        </div>
        <div v-if="!data" class="p-6 text-center text-slate-400">Loading…</div>
        <template v-else>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Bought</div>
                    <div class="text-2xl font-semibold text-slate-800">{{ money(data.purchased_mbps) }} <span class="text-sm font-normal text-slate-500">Mbps</span></div>
                    <div class="text-xs text-slate-500">Tk {{ money(data.monthly_cost) }} / month</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Sold (active connections)</div>
                    <div class="text-2xl font-semibold text-slate-800">{{ money(data.sold_mbps) }} <span class="text-sm font-normal text-slate-500">Mbps</span></div>
                    <div class="text-xs text-slate-500">{{ data.active_connections }} active · {{ data.suspended_connections }} suspended (not counted)</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Contention (sold ÷ bought)</div>
                    <div class="text-2xl font-semibold text-slate-800">{{ data.contention !== null ? `${data.contention} : 1` : '—' }}</div>
                    <div class="text-xs" :class="oversold ? 'text-amber-600' : 'text-slate-500'">
                        <i v-if="oversold" class="bi bi-exclamation-triangle"></i>
                        {{ data.purchased_mbps ? (oversold ? `Oversold by ${money(data.sold_mbps - data.purchased_mbps)} Mbps` : `${money(data.purchased_mbps - data.sold_mbps)} Mbps not sold yet`) : 'No bandwidth purchase recorded' }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Monthly profit on bandwidth</div>
                    <div class="text-2xl font-semibold" :class="data.monthly_profit < 0 ? 'text-red-600' : 'text-slate-800'">Tk {{ money(data.monthly_profit) }}</div>
                    <div class="text-xs text-slate-500">Revenue Tk {{ money(data.monthly_revenue) }} − cost Tk {{ money(data.monthly_cost) }}</div>
                </div>
            </div>

            <div class="mt-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-1 flex justify-between text-xs text-slate-500">
                    <span>Sold {{ money(data.sold_mbps) }} Mbps</span>
                    <span>Bought {{ money(data.purchased_mbps) }} Mbps</span>
                </div>
                <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100" role="img" :aria-label="`Sold ${data.sold_mbps} of ${data.purchased_mbps} Mbps`">
                    <div class="h-full rounded-full" :class="oversold ? 'bg-amber-500' : 'bg-brand-500'" :style="{ width: `${soldPct}%` }"></div>
                </div>
                <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-500">
                    <span>Cost: <b class="text-slate-700">{{ data.cost_per_mbps !== null ? `Tk ${money(data.cost_per_mbps)}` : '—' }}</b> per bought Mbps</span>
                    <span>Revenue: <b class="text-slate-700">{{ data.revenue_per_mbps !== null ? `Tk ${money(data.revenue_per_mbps)}` : '—' }}</b> per sold Mbps</span>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-5">
                <div class="rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-3">
                    <div class="border-b border-slate-200 p-3 text-sm font-semibold text-slate-700">Sold by package (active connections)</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                    <th class="px-3 py-2 font-medium">Package</th>
                                    <th class="px-3 py-2 text-right font-medium">Speed</th>
                                    <th class="px-3 py-2 text-right font-medium">Connections</th>
                                    <th class="px-3 py-2 text-right font-medium">Sold Mbps</th>
                                    <th class="px-3 py-2 text-right font-medium">Share</th>
                                    <th class="px-3 py-2 text-right font-medium">Revenue / month</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in data.packages" :key="p.id" class="border-b border-slate-100">
                                    <td class="px-3 py-2 font-medium text-slate-800">{{ p.name }}</td>
                                    <td class="px-3 py-2 text-right">{{ p.download_mbps }}/{{ p.upload_mbps }}</td>
                                    <td class="px-3 py-2 text-right">{{ p.connections }}</td>
                                    <td class="px-3 py-2 text-right">{{ money(p.sold_mbps) }}</td>
                                    <td class="px-3 py-2 text-right text-slate-500">{{ data.sold_mbps ? ((p.sold_mbps / data.sold_mbps) * 100).toFixed(1) : 0 }}%</td>
                                    <td class="px-3 py-2 text-right">{{ money(p.monthly_revenue) }}</td>
                                </tr>
                                <tr v-if="!data.packages.length"><td colspan="6" class="px-3 py-6 text-center text-slate-400">No active connections</td></tr>
                            </tbody>
                            <tfoot v-if="data.packages.length">
                                <tr class="font-semibold text-slate-800">
                                    <td class="px-3 py-2" colspan="2">Total</td>
                                    <td class="px-3 py-2 text-right">{{ data.active_connections }}</td>
                                    <td class="px-3 py-2 text-right">{{ money(data.sold_mbps) }}</td>
                                    <td></td>
                                    <td class="px-3 py-2 text-right">{{ money(data.monthly_revenue) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-2">
                    <div class="border-b border-slate-200 p-3 text-sm font-semibold text-slate-700">Bought (running now)</div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="px-3 py-2 font-medium">Provider</th>
                                <th class="px-3 py-2 text-right font-medium">Mbps</th>
                                <th class="px-3 py-2 text-right font-medium">Cost / month</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in data.purchases" :key="p.id" class="border-b border-slate-100">
                                <td class="px-3 py-2">{{ p.provider }} <span class="text-xs uppercase text-slate-400">{{ p.type }}</span></td>
                                <td class="px-3 py-2 text-right">{{ money(p.bandwidth_mbps) }}</td>
                                <td class="px-3 py-2 text-right">{{ money(p.monthly_cost) }}</td>
                            </tr>
                            <tr v-if="!data.purchases.length"><td colspan="3" class="px-3 py-6 text-center text-slate-400"><Link href="/isp/bandwidth" class="text-brand-600 hover:underline">Record a bandwidth purchase</Link></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Sold = download speed × active connections. Revenue / month = package price minus discount, divided by the billing cycle length (customer price; a reseller's package counts at the customer price here).</p>
        </template>
    </div>
</template>
