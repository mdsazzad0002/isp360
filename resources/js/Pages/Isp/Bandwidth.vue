<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { money, fmtDate, today, useApiError } from '../../lib/isp';

// Upstream bandwidth bought (IIG / NTTN / transit...) as a monthly cost from start to end date.
defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const TYPES = { iig: 'IIG', nttn: 'NTTN', transit: 'IP Transit', cache: 'Cache (GGC/FNA/CDN)', other: 'Other' };
const blank = () => ({ id: null, provider: '', type: 'iig', bandwidth_mbps: '', monthly_cost: '', start_date: today(), end_date: '', notes: '' });
const form = reactive(blank());
const rows = ref([]);
const saving = ref(false);
const showEnded = ref(false);

const visible = computed(() => (showEnded.value ? rows.value : rows.value.filter((r) => r.running || r.start_date > today())));
const running = computed(() => rows.value.filter((r) => r.running));
const totalMbps = computed(() => running.value.reduce((s, r) => s + Number(r.bandwidth_mbps), 0));
const totalCost = computed(() => running.value.reduce((s, r) => s + Number(r.monthly_cost), 0));

function load() {
    axios.post('/isp/get-bandwidth').then((r) => (rows.value = r.data));
}
async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/bandwidth', { ...form, end_date: form.end_date || null });
        toast.success(res.data.message);
        Object.assign(form, blank());
        load();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
function edit(r) {
    Object.assign(form, { id: r.id, provider: r.provider, type: r.type, bandwidth_mbps: Number(r.bandwidth_mbps), monthly_cost: Number(r.monthly_cost), start_date: r.start_date, end_date: r.end_date || '', notes: r.notes || '' });
}
async function remove(r) {
    if (!(await confirmDialog({ title: `Delete ${r.provider} (${Number(r.bandwidth_mbps)} Mbps)?`, text: 'To stop a running purchase, set its end date instead — past months keep their cost in the profit report.' }))) return;
    try {
        const res = await axios.post('/isp/delete-bandwidth', { id: r.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
onMounted(load);
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ form.id ? `Edit purchase: ${form.provider}` : 'Bandwidth Purchase' }}</h1>
            <form class="grid grid-cols-1 gap-3 md:grid-cols-12" @submit.prevent="save">
                <div class="md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Provider</label>
                    <input v-model="form.provider" required placeholder="e.g. Summit Communications" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                    <select v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option v-for="(l, k) in TYPES" :key="k" :value="k">{{ l }}</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Bandwidth (Mbps)</label>
                    <input v-model="form.bandwidth_mbps" type="number" min="0.01" step="0.01" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Monthly cost (Tk)</label>
                    <input v-model="form.monthly_cost" type="number" min="0" step="0.01" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <div v-if="Number(form.bandwidth_mbps) > 0 && form.monthly_cost !== ''" class="mt-0.5 text-xs text-slate-500">Tk {{ money(form.monthly_cost / form.bandwidth_mbps) }} / Mbps</div>
                </div>
                <div class="md:col-span-3 grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Start</label>
                        <input v-model="form.start_date" type="date" required class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">End <span class="text-slate-400">(blank = running)</span></label>
                        <input v-model="form.end_date" type="date" :min="form.start_date" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="md:col-span-9">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Notes</label>
                    <input v-model="form.notes" placeholder="Contract no., link / port, etc." class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="flex items-end justify-end gap-2 md:col-span-3">
                    <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="Object.assign(form, blank())">Reset</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Bought now</div>
                <div class="text-xl font-semibold text-slate-800">{{ money(totalMbps) }} <span class="text-sm font-normal text-slate-500">Mbps</span></div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Monthly bandwidth bill</div>
                <div class="text-xl font-semibold text-slate-800">Tk {{ money(totalCost) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Average cost</div>
                <div class="text-xl font-semibold text-slate-800">{{ totalMbps ? `Tk ${money(totalCost / totalMbps)}` : '—' }} <span class="text-sm font-normal text-slate-500">/ Mbps</span></div>
            </div>
            <div class="flex flex-col justify-center gap-1 rounded-lg border border-slate-200 bg-white p-3 text-sm shadow-sm">
                <Link href="/isp/bandwidth-usage" class="text-brand-600 hover:underline"><i class="bi bi-speedometer"></i> Bought vs sold report</Link>
                <Link href="/isp/bandwidth-profit" class="text-brand-600 hover:underline"><i class="bi bi-bar-chart-line"></i> Profit report</Link>
            </div>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ visible.length }} purchase(s)</span>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input v-model="showEnded" type="checkbox" /> Show ended</label>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Provider</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 text-right font-medium">Mbps</th>
                            <th class="px-3 py-2 text-right font-medium">Monthly cost</th>
                            <th class="px-3 py-2 text-right font-medium">Tk / Mbps</th>
                            <th class="px-3 py-2 font-medium">Period</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in visible" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium text-slate-800">{{ r.provider }}<div v-if="r.notes" class="text-xs font-normal text-slate-400">{{ r.notes }}</div></td>
                            <td class="px-3 py-2">{{ TYPES[r.type] }}</td>
                            <td class="px-3 py-2 text-right">{{ money(r.bandwidth_mbps) }}</td>
                            <td class="px-3 py-2 text-right">{{ money(r.monthly_cost) }}</td>
                            <td class="px-3 py-2 text-right text-slate-500">{{ money(r.monthly_cost / r.bandwidth_mbps) }}</td>
                            <td class="px-3 py-2">{{ fmtDate(r.start_date) }} – {{ r.end_date ? fmtDate(r.end_date) : 'running' }}</td>
                            <td class="px-3 py-2">
                                <span v-if="r.running" class="text-emerald-600">Running</span>
                                <span v-else-if="r.start_date > today()" class="text-amber-600">Starts later</span>
                                <span v-else class="text-slate-400">Ended</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i class="bi bi-pen cursor-pointer text-brand-500" title="Edit" @click="edit(r)"></i>
                                    <i class="bi bi-trash cursor-pointer text-red-500" title="Delete" @click="remove(r)"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!visible.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No bandwidth purchases yet</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
