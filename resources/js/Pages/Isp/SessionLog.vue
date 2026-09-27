<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import { fmtDateTime, useApiError, promptReason } from '../../lib/isp';

defineOptions({ layout: AppLayout });
defineProps({ retention_days: { type: Number, default: 365 } });
const showError = useApiError();
const filter = reactive({ ip: '', port: '', username: '', mac: '', from: '', to: '' });
const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        const r = await axios.post('/isp/get-session-log', { ...filter, page: page.value });
        rows.value = r.data.data;
        lastPage.value = r.data.last_page;
        total.value = r.data.total;
    } catch (err) {
        showError(err);
    } finally {
        loading.value = false;
    }
}
function search() {
    page.value = 1;
    load();
}
const bytes = (n) => (n == null ? '' : n >= 1e9 ? (n / 1e9).toFixed(2) + ' GB' : n >= 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.round(n / 1e3) + ' KB');

// CSV for a lawful request: the reason is recorded in the audit log with the filters
async function exportCsv() {
    const reason = await promptReason('Export these sessions', { text: 'State the request (authority, reference number). It is kept in the audit log.', confirmButtonText: 'Export CSV' });
    if (!reason) return;
    try {
        const res = await axios.post('/isp/session-log-export', { ...filter, reason }, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = Object.assign(document.createElement('a'), { href: url, download: `session-log-${Date.now()}.csv` });
        a.click();
        URL.revokeObjectURL(url);
    } catch (err) {
        if (err.response?.data instanceof Blob) err.response.data = JSON.parse(await err.response.data.text());
        showError(err);
    }
}
onMounted(load);
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <h1 class="mb-1 text-sm font-semibold text-slate-700">Session log <span class="font-normal text-slate-400">— who had which IP when</span></h1>
            <p class="mb-3 text-xs text-slate-500">From RADIUS accounting and, where switched on in Settings, MikroTik router polling (every 5 minutes). Kept {{ retention_days ? `${retention_days} days` : 'forever' }}. For a regulator request give the IP (private or public NAT address, with the port for CGNAT) and the time.</p>
            <form class="grid grid-cols-2 gap-2 md:grid-cols-7" @submit.prevent="search">
                <input v-model="filter.ip" placeholder="IP address" :class="input" />
                <input v-model="filter.port" type="number" min="1" max="65535" placeholder="NAT port" :class="input" />
                <input v-model="filter.username" placeholder="Username" :class="input" />
                <input v-model="filter.mac" placeholder="MAC" :class="input" />
                <input v-model="filter.from" type="datetime-local" :class="input" title="From" />
                <input v-model="filter.to" type="datetime-local" :class="input" title="To" />
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Search</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" title="Export CSV" @click="exportCsv"><i class="bi bi-download"></i></button>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-xs text-slate-600">
                        <th class="px-2 py-2 font-medium">Start</th>
                        <th class="px-2 py-2 font-medium">Stop</th>
                        <th class="px-2 py-2 font-medium">User / customer</th>
                        <th class="px-2 py-2 font-medium">IP</th>
                        <th class="px-2 py-2 font-medium">NAT</th>
                        <th class="px-2 py-2 font-medium">MAC</th>
                        <th class="px-2 py-2 font-medium">NAS</th>
                        <th class="px-2 py-2 text-end font-medium">Down / Up</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in rows" :key="s.id" class="border-b border-slate-100 text-xs">
                        <td class="whitespace-nowrap px-2 py-1.5">{{ fmtDateTime(s.started_at) }}</td>
                        <td class="whitespace-nowrap px-2 py-1.5">{{ s.stopped_at ? fmtDateTime(s.stopped_at) : 'online' }}</td>
                        <td class="px-2 py-1.5"><span class="font-mono">{{ s.username }}</span><div v-if="s.customer" class="text-slate-500">{{ s.customer.name }} · {{ s.customer.code }}</div></td>
                        <td class="px-2 py-1.5 font-mono">{{ s.framed_ip }}<div v-if="s.framed_ipv6" class="text-slate-400">{{ s.framed_ipv6 }}</div></td>
                        <td class="px-2 py-1.5 font-mono">{{ s.nat_ip ? `${s.nat_ip}:${s.nat_port_start}-${s.nat_port_end}` : '' }}</td>
                        <td class="px-2 py-1.5 font-mono">{{ s.mac }}</td>
                        <td class="px-2 py-1.5 font-mono">{{ s.nas_ip }} <span class="text-slate-400">{{ s.source }}</span></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-end">{{ bytes(s.download_bytes) }} / {{ bytes(s.upload_bytes) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="8" class="px-2 py-6 text-center text-slate-400">{{ loading ? 'Loading…' : 'No sessions found' }}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex items-center justify-between text-xs text-slate-500">
            <span>{{ total }} session(s)</span>
            <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
        </div>
    </div>
</template>
