<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { useToast } from '../../lib/toast';
import { money, fmtDate, label, useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({ can: { type: Object, default: () => ({}) } });
const toast = useToast();
const showError = useApiError();

const tab = ref('withdrawals');
const banks = ref([]);

// ── Withdrawals & deposits ──
const txFilter = reactive({ status: 'pending', type: '', resellerId: '' });
const txRows = ref([]);
const txPage = ref(1);
const txLastPage = ref(1);
function loadTx() {
    axios.post('/isp/get-reseller-transactions', { page: txPage.value, ...txFilter }).then((r) => {
        txRows.value = r.data.data;
        txLastPage.value = r.data.last_page;
    });
}
function reloadTx() {
    txPage.value = 1;
    loadTx();
}

// ── Wallets ──
const wallets = ref([]);
function loadWallets() {
    axios.post('/isp/get-reseller-wallets').then((r) => (wallets.value = r.data));
}
const walletOf = (id) => wallets.value.find((w) => w.id === id) || {};

const pay = reactive({ show: false, row: null, method: 'bkash', bank_id: '', transaction_id: '', note: '', saving: false });
function openPay(row) {
    Object.assign(pay, { show: true, row, method: row.method === 'bank' ? 'bank' : row.method, bank_id: '', transaction_id: '', note: '', saving: false });
}
async function submitPay() {
    pay.saving = true;
    try {
        const res = await axios.post('/isp/reseller-withdrawal-pay', { id: pay.row.id, method: pay.method, bank_id: pay.bank_id, transaction_id: pay.transaction_id, note: pay.note });
        toast.success(res.data.message);
        pay.show = false;
        loadTx();
        loadWallets();
    } catch (err) {
        showError(err);
    } finally {
        pay.saving = false;
    }
}

const rejectForm = reactive({ show: false, row: null, note: '', saving: false });
async function submitReject() {
    rejectForm.saving = true;
    try {
        const res = await axios.post('/isp/reseller-withdrawal-reject', { id: rejectForm.row.id, note: rejectForm.note });
        toast.success(res.data.message);
        rejectForm.show = false;
        loadTx();
        loadWallets();
    } catch (err) {
        showError(err);
    } finally {
        rejectForm.saving = false;
    }
}

const dep = reactive({ show: false, reseller: null, amount: '', method: 'cash', bank_id: '', transaction_id: '', note: '', saving: false });
function openDeposit(w) {
    Object.assign(dep, { show: true, reseller: w, amount: w.balance < 0 ? Math.abs(w.balance) : '', method: 'cash', bank_id: '', transaction_id: '', note: '', saving: false });
}
async function submitDeposit() {
    dep.saving = true;
    try {
        const res = await axios.post('/isp/reseller-deposit', { reseller_id: dep.reseller.id, amount: dep.amount, method: dep.method, bank_id: dep.bank_id, transaction_id: dep.transaction_id, note: dep.note });
        toast.success(res.data.message);
        dep.show = false;
        loadWallets();
        loadTx();
    } catch (err) {
        showError(err);
    } finally {
        dep.saving = false;
    }
}

const totals = computed(() => wallets.value.reduce((t, w) => {
    if (w.balance > 0) t.owed += w.balance;
    else t.receivable += -w.balance;
    t.pending += w.pending;
    return t;
}, { owed: 0, receivable: 0, pending: 0 }));

onMounted(() => {
    loadTx();
    loadWallets();
    axios.post('/get-bank').then((res) => (banks.value = res.data));
});
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Company owes resellers</div>
                <div class="text-lg font-semibold text-slate-800">{{ money(totals.owed) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Resellers owe company (cash in hand)</div>
                <div class="text-lg font-semibold text-red-600">{{ money(totals.receivable) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Pending withdrawal requests</div>
                <div class="text-lg font-semibold text-amber-700">{{ money(totals.pending) }}</div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-2">
                <button
                    v-for="[k, text] in [['withdrawals', 'Withdrawals & deposits'], ['wallets', 'Reseller wallets']]"
                    :key="k"
                    type="button"
                    class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm"
                    :class="tab === k ? 'border-brand-500 font-medium text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="tab = k"
                >{{ text }}</button>
            </div>

            <!-- Withdrawals & deposits -->
            <div v-if="tab === 'withdrawals'" class="p-3">
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <select v-model="txFilter.status" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reloadTx">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <select v-model="txFilter.type" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reloadTx">
                        <option value="">Withdrawals & deposits</option>
                        <option value="withdrawal">Withdrawals</option>
                        <option value="deposit">Deposits</option>
                    </select>
                    <select v-model="txFilter.resellerId" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reloadTx">
                        <option value="">All resellers</option>
                        <option v-for="w in wallets" :key="w.id" :value="w.id">{{ w.name }}</option>
                    </select>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="px-3 py-2 font-medium">Ref</th>
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Reseller</th>
                                <th class="px-3 py-2 font-medium">Type</th>
                                <th class="px-3 py-2 font-medium">Method / account</th>
                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                                <th class="px-3 py-2 text-right font-medium">Reseller balance</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in txRows" :key="row.id" class="border-b border-slate-100 align-top hover:bg-slate-50">
                                <td class="px-3 py-2 font-medium">{{ row.ref_no }}</td>
                                <td class="px-3 py-2">{{ fmtDate(row.created_at) }}<div v-if="row.processed_by" class="text-xs text-slate-400">{{ row.processed_by.name }} · {{ fmtDate(row.processed_at) }}</div></td>
                                <td class="px-3 py-2">{{ row.reseller?.name }}<div class="text-xs text-slate-400">{{ row.reseller?.phone }}</div></td>
                                <td class="px-3 py-2">{{ row.type === 'deposit' ? 'Deposit' : 'Withdrawal' }}</td>
                                <td class="px-3 py-2">
                                    {{ label(row.method) }}
                                    <div class="text-xs text-slate-400">{{ row.account_details }}<span v-if="row.bank"> · {{ row.bank.name }}</span><span v-if="row.transaction_id"> · {{ row.transaction_id }}</span></div>
                                    <div v-if="row.reseller_note" class="text-xs text-slate-500">Reseller: {{ row.reseller_note }}</div>
                                    <div v-if="row.admin_note" class="text-xs text-slate-500">Note: {{ row.admin_note }}</div>
                                </td>
                                <td class="px-3 py-2 text-right font-medium">{{ money(row.amount) }}</td>
                                <td class="px-3 py-2 text-right text-xs" :class="walletOf(row.reseller_id).balance < 0 ? 'text-red-600' : 'text-slate-600'">{{ money(walletOf(row.reseller_id).balance) }}</td>
                                <td class="px-3 py-2"><StatusBadge :status="row.status" /></td>
                                <td class="px-3 py-2 text-right">
                                    <div v-if="row.type === 'withdrawal' && row.status === 'pending' && can.settle" class="flex justify-end gap-2">
                                        <button type="button" class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs text-white" @click="openPay(row)">Mark paid</button>
                                        <button type="button" class="rounded-md bg-red-600 px-2.5 py-1 text-xs text-white" @click="Object.assign(rejectForm, { show: true, row, note: '', saving: false })">Reject</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!txRows.length"><td colspan="9" class="px-3 py-6 text-center text-slate-400">Nothing here</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination v-if="txLastPage > 1" :page="txPage" :total-pages="txLastPage" @change="(p) => { txPage = p; loadTx(); }" />
            </div>

            <!-- Wallets -->
            <div v-if="tab === 'wallets'" class="p-3">
                <p class="mb-3 text-xs text-slate-500">
                    Balance = reseller margin on paid bills − cash the reseller collected + deposits − paid withdrawals. Positive: the company owes the reseller. Negative: the reseller holds company money.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="px-3 py-2 font-medium">Reseller</th>
                                <th class="px-3 py-2 text-right font-medium">Earned</th>
                                <th class="px-3 py-2 text-right font-medium">Collected</th>
                                <th class="px-3 py-2 text-right font-medium">Deposited</th>
                                <th class="px-3 py-2 text-right font-medium">Withdrawn</th>
                                <th class="px-3 py-2 text-right font-medium">Pending</th>
                                <th class="px-3 py-2 text-right font-medium">Balance</th>
                                <th class="px-3 py-2 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in wallets" :key="w.id" class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-3 py-2">{{ w.name }}<div class="text-xs text-slate-400">{{ w.code }} · {{ w.phone }}</div></td>
                                <td class="px-3 py-2 text-right">{{ money(w.earned) }}</td>
                                <td class="px-3 py-2 text-right">{{ money(w.collected) }}</td>
                                <td class="px-3 py-2 text-right">{{ money(w.deposits) }}</td>
                                <td class="px-3 py-2 text-right">{{ money(w.withdrawn) }}</td>
                                <td class="px-3 py-2 text-right text-amber-700">{{ money(w.pending) }}</td>
                                <td class="px-3 py-2 text-right font-semibold" :class="w.balance < 0 ? 'text-red-600' : 'text-emerald-700'">{{ w.balance < 0 ? '−' : '' }}{{ money(Math.abs(w.balance)) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a :href="`/isp/reseller-ledger?resellerId=${w.id}`" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs">Ledger</a>
                                        <button v-if="can.settle" type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs" @click="openDeposit(w)">Record deposit</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!wallets.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No resellers</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pay withdrawal modal -->
        <Modal :show="pay.show" max-width="max-w-md" @close="pay.show = false">
            <form v-if="pay.row" class="space-y-3 p-5 text-sm" @submit.prevent="submitPay">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">Pay withdrawal {{ pay.row.ref_no }}</h2>
                    <p class="text-slate-500">{{ pay.row.reseller?.name }} · Tk {{ money(pay.row.amount) }} · {{ label(pay.row.method) }} {{ pay.row.account_details }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Paid by</label>
                        <select v-model="pay.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option v-for="m in ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'other']" :key="m" :value="m">{{ label(m) }}</option>
                        </select>
                    </div>
                    <div v-if="pay.method !== 'cash'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">From account</label>
                        <select v-model="pay.bank_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option value="" disabled>— Select —</option>
                            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.display_name || b.name }}</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                        <input v-model="pay.transaction_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input v-model="pay.note" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="pay.show = false">Close</button>
                    <button type="submit" :disabled="pay.saving" class="rounded-md bg-emerald-600 px-4 py-1.5 text-white disabled:opacity-50">Mark paid</button>
                </div>
            </form>
        </Modal>

        <!-- Reject withdrawal modal -->
        <Modal :show="rejectForm.show" max-width="max-w-md" @close="rejectForm.show = false">
            <form v-if="rejectForm.row" class="p-5 text-sm" @submit.prevent="submitReject">
                <h2 class="mb-1 text-base font-semibold text-slate-800">Reject withdrawal {{ rejectForm.row.ref_no }}</h2>
                <p class="mb-4 text-slate-500">{{ rejectForm.row.reseller?.name }} · Tk {{ money(rejectForm.row.amount) }}</p>
                <label class="mb-1 block text-xs font-medium text-slate-600">Reason</label>
                <input v-model="rejectForm.note" required minlength="3" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="rejectForm.show = false">Close</button>
                    <button type="submit" :disabled="rejectForm.saving" class="rounded-md bg-red-600 px-4 py-1.5 text-white disabled:opacity-50">Reject</button>
                </div>
            </form>
        </Modal>

        <!-- Deposit modal -->
        <Modal :show="dep.show" max-width="max-w-md" @close="dep.show = false">
            <form v-if="dep.reseller" class="space-y-3 p-5 text-sm" @submit.prevent="submitDeposit">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">Record deposit from {{ dep.reseller.name }}</h2>
                    <p class="text-slate-500">Cash the reseller collected and handed over to the company.</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Amount (Tk)</label>
                        <input v-model="dep.amount" type="number" min="0.01" step="0.01" required class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Received by</label>
                        <select v-model="dep.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option v-for="m in ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'other']" :key="m" :value="m">{{ label(m) }}</option>
                        </select>
                    </div>
                    <div v-if="dep.method !== 'cash'" class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Into account</label>
                        <select v-model="dep.bank_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option value="" disabled>— Select —</option>
                            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.display_name || b.name }}</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                        <input v-model="dep.transaction_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input v-model="dep.note" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="dep.show = false">Close</button>
                    <button type="submit" :disabled="dep.saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Save deposit</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
