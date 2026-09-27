<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { useToast } from '../../lib/toast';
import { money, fmtDate, label, monthStart, today, useApiError, fmtDateTime, fmtMoney, cur, moneyStep } from '../../lib/isp';

// Reseller collects a bill from one of their customers (cash or into their own mobile
// wallet). The money is credited to the customer at once and counted in the reseller's
// wallet as money in hand, to settle with the company. "My wallet" pays the bill from the
// reseller's wallet balance instead.
const props = defineProps({
    reseller: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
});

const toast = useToast();
const showError = useApiError();

const METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'bkash', label: 'bKash' },
    { value: 'nagad', label: 'Nagad' },
    { value: 'rocket', label: 'Rocket' },
    { value: 'other', label: 'Other' },
    { value: 'wallet', label: 'Pay from my wallet' },
];
const needsTrx = computed(() => !['cash', 'wallet'].includes(form.method));

function blank() {
    return { customer_id: '', amount: '', method: 'cash', transaction_id: '', notes: '' };
}
const form = reactive(blank());
const customerSearch = ref('');
const dues = ref(null);
const saving = ref(false);

const customerOptions = computed(() => {
    const t = customerSearch.value.trim().toLowerCase();
    const list = t ? props.customers.filter((c) => `${c.name} ${c.code} ${c.phone}`.toLowerCase().includes(t)) : props.customers;
    return list.slice(0, 200);
});

watch(
    () => form.customer_id,
    async (id) => {
        dues.value = null;
        if (!id) return;
        const res = await axios.post('/reseller/get-customer-dues', { customerId: id });
        dues.value = res.data;
        if (!form.amount && res.data.balance > 0) form.amount = res.data.balance;
    },
);

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/reseller/payment', { ...form });
        toast.success(res.data.message);
        Object.assign(form, blank());
        customerSearch.value = '';
        reload();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

// History
const rows = ref([]);
const totals = ref({});
const page = ref(1);
const lastPage = ref(1);
const filter = reactive({ search: '', mine: false, dateFrom: monthStart(), dateTo: today() });
let timer = null;

function load() {
    axios.post('/reseller/get-payments', { page: page.value, per_page: 20, ...filter }).then((res) => {
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

onMounted(load);
</script>

<template>
    <PortalLayout :user-name="reseller.name" logout-url="/reseller/logout">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">Payment</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">Collect bill</h2>
            <p class="mb-3 text-xs text-slate-500">The payment is added to the customer's account right away. Money you collect stays with you and is settled with the company from your wallet.</p>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-4" @submit.prevent="save">
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
                    <input v-model="customerSearch" placeholder="Search name, code, phone" class="mb-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <select v-model="form.customer_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="" disabled>— Select customer ({{ customerOptions.length }}) —</option>
                        <option v-for="c in customerOptions" :key="c.id" :value="c.id">{{ c.name }} · {{ c.code }} · {{ c.phone }}{{ c.ledger_balance > 0 ? ` · due ${money(c.ledger_balance)}` : '' }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Amount ({{ cur() }})</label>
                    <input v-model="form.amount" type="number" :min="moneyStep()" :step="moneyStep()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                    <select v-model="form.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option v-for="m in METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>
                <div v-if="needsTrx">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                    <input v-model="form.transaction_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div :class="needsTrx ? 'col-span-1 md:col-span-3' : 'col-span-2 md:col-span-4'">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                    <input v-model="form.notes" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>

                <div v-if="dues" class="col-span-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm md:col-span-4">
                    <div class="mb-2 flex flex-wrap gap-4">
                        <span>Current due: <b :class="dues.balance > 0 ? 'text-red-600' : 'text-slate-700'">{{ money(Math.max(0, dues.balance)) }}</b></span>
                        <span v-if="dues.advance > 0">Advance: <b class="text-emerald-700">{{ money(dues.advance) }}</b></span>
                        <span v-if="form.method === 'wallet'">My wallet available: <b :class="dues.wallet >= Number(form.amount || 0) ? 'text-emerald-700' : 'text-red-600'">{{ money(dues.wallet) }}</b></span>
                    </div>
                    <table v-if="dues.invoices.length" class="w-full text-xs">
                        <thead>
                            <tr class="text-start text-slate-500">
                                <th class="py-1 font-medium">Invoice</th>
                                <th class="py-1 font-medium">Period</th>
                                <th class="py-1 font-medium">Due date</th>
                                <th class="py-1 text-end font-medium">Total</th>
                                <th class="py-1 text-end font-medium">Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in dues.invoices" :key="inv.id" class="border-t border-slate-200">
                                <td class="py-1">{{ inv.invoice_no }}</td>
                                <td class="py-1">{{ fmtDateTime(inv.period_start) }}<span v-if="inv.period_end"> – {{ fmtDateTime(inv.period_end) }}</span></td>
                                <td class="py-1">{{ fmtDate(inv.due_date) }}</td>
                                <td class="py-1 text-end">{{ money(inv.total) }}</td>
                                <td class="py-1 text-end font-medium text-red-600">{{ money(inv.due) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="text-xs text-slate-500">No open invoices. A payment now is kept as advance for the next bill.</div>
                </div>

                <div class="col-span-2 flex justify-end md:col-span-4">
                    <button type="submit" :disabled="saving" class="rounded-md bg-emerald-600 px-4 py-1.5 text-sm text-white hover:bg-emerald-700 disabled:opacity-50"><i class="bi bi-check2-circle"></i> {{ form.method === 'wallet' ? 'Pay from wallet' : 'Receive payment' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input v-model="filter.search" placeholder="Receipt, TrxID, customer" class="w-52 rounded-md border border-slate-300 px-3 py-1.5 text-sm" @input="onSearch" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                        <input v-model="filter.dateFrom" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                        <input v-model="filter.dateTo" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload" />
                    </div>
                    <label class="flex items-center gap-2 pb-1.5 text-sm text-slate-600"><input v-model="filter.mine" type="checkbox" @change="reload" /> Collected by me only</label>
                </div>
                <div class="text-end text-sm">
                    <div class="text-slate-500">{{ totals.count ?? 0 }} payments</div>
                    <div class="text-base font-semibold text-emerald-700">{{ fmtMoney(totals.amount) }}</div>
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
                            <th class="px-2 py-2 font-medium">Collected by</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-2 font-medium">{{ row.receipt_no }}</td>
                            <td class="px-2 py-2">{{ fmtDate(row.payment_date) }}</td>
                            <td class="px-2 py-2">{{ row.customer?.name }}<div class="text-xs text-slate-400">{{ row.customer?.code }} · {{ row.customer?.phone }}</div></td>
                            <td class="px-2 py-2">{{ label(row.method) }}<div v-if="row.transaction_id" class="text-xs text-slate-400">{{ row.transaction_id }}</div></td>
                            <td class="px-2 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                            <td class="px-2 py-2">
                                <span v-if="row.collected_by_reseller_id" class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">Me</span>
                                <span v-else class="text-xs text-slate-500">Company ({{ label(row.source) }})</span>
                            </td>
                            <td class="px-2 py-2"><StatusBadge :status="row.status" /></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="7" class="px-2 py-6 text-center text-slate-400">No payments in this range</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
        </div>
    </PortalLayout>
</template>
