<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';

defineOptions({ layout: AppLayout });
const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const expanded = ref(null);
const filter = reactive({ action: '', subject: '', dateFrom: '', dateTo: '' });
const groups = ['payment', 'allocation', 'invoice', 'billing', 'connection', 'package', 'zone', 'box', 'settings'];

function load() {
    axios.post('/isp/get-audit-log', { page: page.value, ...filter }).then((r) => {
        rows.value = r.data.data;
        lastPage.value = r.data.last_page;
    });
}
function reload() {
    page.value = 1;
    load();
}
function when(v) {
    return new Date(v).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function diff(row) {
    const keys = new Set([...Object.keys(row.old_values || {}), ...Object.keys(row.new_values || {})]);
    return [...keys].map((k) => ({ k, o: row.old_values?.[k], n: row.new_values?.[k] }));
}
onMounted(load);
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                    <select v-model="filter.action" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">All actions</option>
                        <option v-for="g in groups" :key="g" :value="g + '.'">{{ g }}</option>
                    </select>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-slate-600">From</label><input v-model="filter.dateFrom" type="date" @change="reload" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
                <div><label class="mb-1 block text-xs font-medium text-slate-600">To</label><input v-model="filter.dateTo" type="date" @change="reload" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">When</th>
                            <th class="px-2 py-2 font-medium">Who</th>
                            <th class="px-2 py-2 font-medium">Action</th>
                            <th class="px-2 py-2 font-medium">Record</th>
                            <th class="px-2 py-2 font-medium">Reason</th>
                            <th class="px-2 py-2 font-medium">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows" :key="r.id">
                            <tr class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="expanded = expanded === r.id ? null : r.id">
                                <td class="whitespace-nowrap px-2 py-2">{{ when(r.created_at) }}</td>
                                <td class="px-2 py-2">{{ r.user_type === 'reseller' ? `Reseller: ${r.reseller?.name ?? ''}` : r.user?.name || 'System' }}</td>
                                <td class="px-2 py-2 font-mono text-xs">{{ r.action }}</td>
                                <td class="px-2 py-2">{{ r.auditable_type }} <span class="text-slate-400">#{{ r.auditable_id }}</span></td>
                                <td class="px-2 py-2">{{ r.reason }}</td>
                                <td class="px-2 py-2 text-xs text-slate-400">{{ r.ip_address }}</td>
                            </tr>
                            <tr v-if="expanded === r.id" class="bg-slate-50">
                                <td colspan="6" class="px-4 py-2">
                                    <div v-for="d in diff(r)" :key="d.k" class="font-mono text-xs"><span class="text-slate-500">{{ d.k }}:</span> <span v-if="d.o !== undefined" class="text-red-600">{{ d.o }}</span><span v-if="d.o !== undefined && d.n !== undefined"> → </span><span v-if="d.n !== undefined" class="text-emerald-700">{{ d.n }}</span></div>
                                    <div v-if="!diff(r).length" class="text-xs text-slate-400">No field changes recorded</div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.length"><td colspan="6" class="px-2 py-6 text-center text-slate-400">No audit entries</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
        </div>
    </div>
</template>
