<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import ReceivePaymentForm from '../../Components/Isp/ReceivePaymentForm.vue';
import PaymentDetailModal from '../../Components/Isp/PaymentDetailModal.vue';
import { money, fmtDate, label, today, PAYMENT_METHODS, fmtMoney } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({ preselectCustomerId: { type: Number, default: null }, can: { type: Object, default: () => ({}) } });

const rows = ref([]);
const totals = ref({});
const page = ref(1);
const lastPage = ref(1);
const filter = reactive({ search: '', status: '', method: '', dateFrom: today(), dateTo: today() });
const detail = reactive({ show: false, id: null });
let timer = null;

function load() {
    axios.post('/isp/get-payments', { page: page.value, per_page: 20, ...filter }).then((res) => {
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
function onSaved(id) {
    load();
    Object.assign(detail, { show: true, id });
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Bill Collection</h1>
            <ReceivePaymentForm :customer-id="preselectCustomerId" @saved="onSaved" />
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input v-model="filter.search" @input="onSearch" placeholder="Receipt, TrxID, customer" class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                        <select v-model="filter.method" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                        <select v-model="filter.status" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option v-for="s in ['completed', 'reversed', 'partially_refunded', 'refunded']" :key="s" :value="s">{{ label(s) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                        <input v-model="filter.dateFrom" type="date" @change="reload" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                        <input v-model="filter.dateTo" type="date" @change="reload" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="text-end text-sm">
                    <div class="text-slate-500">{{ totals.count ?? 0 }} payments</div>
                    <div class="text-base font-semibold text-emerald-700">{{ fmtMoney((totals.amount || 0) - (totals.refunded || 0)) }} <span class="text-xs font-normal text-slate-400">net</span></div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Receipt</th>
                            <th class="px-2 py-2 font-medium">Date</th>
                            <th class="px-2 py-2 font-medium">Customer</th>
                            <th class="px-2 py-2 font-medium">Method</th>
                            <th class="px-2 py-2 text-end font-medium">Amount</th>
                            <th class="px-2 py-2 text-end font-medium">Advance</th>
                            <th class="px-2 py-2 font-medium">Received by</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="Object.assign(detail, { show: true, id: row.id })">
                            <td class="px-2 py-2 font-medium text-brand-600">{{ row.receipt_no }}</td>
                            <td class="px-2 py-2">{{ fmtDate(row.payment_date) }}</td>
                            <td class="px-2 py-2"><Link :href="`/isp/customer/${row.customer_id}`" class="hover:underline" @click.stop>{{ row.customer?.name }}</Link><div class="text-xs text-slate-400">{{ row.customer?.code }} · {{ row.customer?.phone }}</div></td>
                            <td class="px-2 py-2">{{ label(row.method) }}<div v-if="row.transaction_id" class="text-xs text-slate-400">{{ row.transaction_id }}</div></td>
                            <td class="px-2 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                            <td class="px-2 py-2 text-end text-emerald-700">{{ Number(row.unallocated) ? money(row.unallocated) : '' }}</td>
                            <td class="px-2 py-2 text-slate-500">{{ row.received_by?.name || row.source }}</td>
                            <td class="px-2 py-2"><StatusBadge :status="row.status" /></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-2 py-6 text-center text-slate-400">No payments in this range</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
        </div>

        <PaymentDetailModal :show="detail.show" :payment-id="detail.id" :can="can" @close="detail.show = false" @changed="load" />
    </div>
</template>
