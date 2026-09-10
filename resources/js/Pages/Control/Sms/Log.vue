<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import Pagination from '../../../Components/Pagination.vue';
import { useToast } from '../../../lib/toast';
import { formatDateTimeAmPm } from '../../../lib/dateFormat';

defineOptions({ layout: AppLayout });

const toast = useToast();

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function monthStartStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

const dateFrom = ref(monthStartStr());
const dateTo = ref(todayStr());
const search = ref('');
const status = ref('');

const rows = ref([]);
const isLoading = ref(null);
const currentPage = ref(1);
const perPage = ref(20);
const total = ref(0);
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

async function fetchLogs() {
    isLoading.value = false;
    try {
        const res = await axios.post('/get-sms-log', {
            dateFrom: dateFrom.value,
            dateTo: dateTo.value,
            search: search.value,
            status: status.value,
            page: currentPage.value,
            perPage: perPage.value,
        });
        rows.value = res.data.data;
        total.value = res.data.total;
    } catch (err) {
        toast.error(err.response?.data?.message || 'Failed to load SMS log');
    } finally {
        isLoading.value = true;
    }
}

function showReport() {
    currentPage.value = 1;
    fetchLogs();
}

function goToPage(p) {
    if (p < 1 || p > totalPages.value || p === currentPage.value) return;
    currentPage.value = p;
    fetchLogs();
}

onMounted(fetchLogs);
</script>

<template>
    <div class="mx-auto p-4">
        <div class="mb-3 flex items-center gap-2">
            <i class="bi bi-list-check text-xl text-brand-500"></i>
            <h1 class="text-lg font-semibold text-slate-800">SMS Log</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <form @submit.prevent="showReport" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                    <input type="date" v-model="dateFrom" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <input type="date" v-model="dateTo" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                    <select v-model="status" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">All</option>
                        <option value="success">Success</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Phone / Message</label>
                    <input type="text" v-model="search" placeholder="Search phone or message" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <button type="submit" class="flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">
                    <i class="bi bi-search"></i> Show
                </button>
            </form>
        </div>

        <div v-if="isLoading" class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                <span class="text-sm text-slate-500">{{ total }} record(s) found</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Date/Time</th>
                            <th class="px-2 py-2 font-medium">Customer</th>
                            <th class="px-2 py-2 font-medium">Phone</th>
                            <th class="px-2 py-2 font-medium">Message</th>
                            <th class="px-2 py-2 font-medium">Gateway</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 font-medium">Response</th>
                            <th class="px-2 py-2 font-medium">Sent By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5 whitespace-nowrap">{{ formatDateTimeAmPm(row.created_at, true) }}</td>
                            <td class="px-2 py-1.5">{{ row.customer_name }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="max-w-xs truncate px-2 py-1.5" :title="row.message">{{ row.message }}</td>
                            <td class="px-2 py-1.5">{{ row.gateway_name || 'N/A' }}</td>
                            <td class="px-2 py-1.5">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="row.is_success ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                                    {{ row.is_success ? 'Success' : 'Failed' }}
                                </span>
                            </td>
                            <td class="max-w-xs truncate px-2 py-1.5 text-xs text-slate-500" :title="row.response">{{ row.response }}</td>
                            <td class="px-2 py-1.5">{{ row.sent_by }}</td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="8" class="px-2 py-6 text-center text-slate-400">No SMS log found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-4 pb-3">
                <Pagination :page="currentPage" :total-pages="totalPages" @change="goToPage" />
            </div>
        </div>
    </div>
</template>
