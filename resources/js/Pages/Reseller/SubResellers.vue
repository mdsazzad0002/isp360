<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import ResellerStatement from '../../Components/Isp/ResellerStatement.vue';
import { useToast } from '../../lib/toast';
import { money, fmtMoney, fmtDate, label, useApiError, cur, moneyStep } from '../../lib/isp';

// A parent reseller's own sub-resellers: their wallets with this reseller (positive: this
// reseller owes them), the cash they hand over (deposits) and their withdrawal requests.
const props = defineProps({
    reseller: { type: Object, required: true },
});

const toast = useToast();
const showError = useApiError();

const children = ref([]);
const requests = ref([]);
const loaded = ref(false);
const ledgerOf = ref(null);

function load() {
    axios.post('/reseller/get-sub-resellers').then((res) => {
        children.value = res.data.children;
        requests.value = res.data.requests;
        loaded.value = true;
    });
}

const pending = computed(() => requests.value.filter((r) => r.type === 'withdrawal' && r.status === 'pending'));
const totals = computed(() => children.value.reduce((t, c) => {
    if (c.balance > 0) t.owe += c.balance;
    else t.owed += -c.balance;
    return t;
}, { owe: 0, owed: 0 }));

const METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'bank', 'other'];

const dep = reactive({ show: false, child: null, amount: '', method: 'cash', transaction_id: '', note: '', saving: false });
function openDeposit(child) {
    Object.assign(dep, { show: true, child, amount: child.balance < 0 ? Math.abs(child.balance) : '', method: 'cash', transaction_id: '', note: '', saving: false });
}
async function saveDeposit() {
    dep.saving = true;
    try {
        const res = await axios.post('/reseller/sub-reseller-deposit', { reseller_id: dep.child.id, amount: dep.amount, method: dep.method, transaction_id: dep.transaction_id, note: dep.note });
        toast.success(res.data.message);
        dep.show = false;
        load();
    } catch (err) {
        showError(err);
    } finally {
        dep.saving = false;
    }
}

const pay = reactive({ show: false, row: null, method: 'cash', transaction_id: '', note: '', saving: false });
function openPay(row) {
    Object.assign(pay, { show: true, row, method: row.method, transaction_id: '', note: '', saving: false });
}
async function savePay() {
    pay.saving = true;
    try {
        const res = await axios.post('/reseller/sub-reseller-withdrawal-pay', { id: pay.row.id, method: pay.method, transaction_id: pay.transaction_id, note: pay.note });
        toast.success(res.data.message);
        pay.show = false;
        load();
    } catch (err) {
        showError(err);
    } finally {
        pay.saving = false;
    }
}

const rej = reactive({ show: false, row: null, note: '', saving: false });
async function saveReject() {
    rej.saving = true;
    try {
        const res = await axios.post('/reseller/sub-reseller-withdrawal-reject', { id: rej.row.id, note: rej.note });
        toast.success(res.data.message);
        rej.show = false;
        load();
    } catch (err) {
        showError(err);
    } finally {
        rej.saving = false;
    }
}

const signed = (v) => (v < 0 ? '−' : '') + money(Math.abs(v || 0));

onMounted(load);
</script>

<template>
    <PortalLayout :user-name="reseller.name" logout-url="/reseller/logout">
        <h1 class="mb-1 text-lg font-semibold text-slate-800">Sub-resellers</h1>
        <p class="mb-4 text-xs text-slate-500">
            Your sub-resellers sell your packages at their own price and settle with you. Their balance counts their sub-resellers too.
            Positive: you owe them. Negative: they hold your money — record it here when they hand it over.
        </p>

        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">They owe you</div>
                <div class="text-lg font-semibold text-red-600">{{ money(totals.owed) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">You owe them</div>
                <div class="text-lg font-semibold text-emerald-700">{{ money(totals.owe) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Withdrawal requests</div>
                <div class="text-lg font-semibold text-amber-700">{{ pending.length }}</div>
            </div>
        </div>

        <div class="mb-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Sub-reseller</th>
                        <th class="px-3 py-2 text-end font-medium">Earned</th>
                        <th class="px-3 py-2 text-end font-medium">Collected</th>
                        <th class="px-3 py-2 text-end font-medium">Deposited</th>
                        <th class="px-3 py-2 text-end font-medium">Balance</th>
                        <th class="px-3 py-2 text-end font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in children" :key="c.id" class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2">
                            {{ c.name }}
                            <span v-if="c.over_limit" class="ms-1 rounded bg-red-100 px-1.5 text-[10px] font-semibold text-red-700">over limit</span>
                            <div class="text-xs text-slate-400">{{ c.code }} · {{ c.phone }}<span v-if="c.sub_count"> · {{ c.sub_count }} sub-reseller(s)</span></div>
                        </td>
                        <td class="px-3 py-2 text-end">{{ money(c.earned + c.downline) }}</td>
                        <td class="px-3 py-2 text-end">{{ money(c.collected + c.downline_collected) }}</td>
                        <td class="px-3 py-2 text-end">{{ money(c.deposits) }}</td>
                        <td class="px-3 py-2 text-end font-semibold" :class="c.balance < 0 ? 'text-red-600' : 'text-emerald-700'">{{ signed(c.balance) }}</td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs" @click="ledgerOf = ledgerOf?.id === c.id ? null : c">Ledger</button>
                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs" @click="openDeposit(c)">Record deposit</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="loaded && !children.length"><td colspan="6" class="px-3 py-6 text-center text-slate-400">No sub-resellers yet. The company adds them with you as the parent.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="ledgerOf" class="mb-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">Ledger: {{ ledgerOf.name }}</h2>
            <ResellerStatement endpoint="/reseller/get-sub-reseller-ledger" :reseller-id="ledgerOf.id" require-reseller />
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">Deposits and withdrawal requests</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Ref</th>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Sub-reseller</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Method / account</th>
                        <th class="px-3 py-2 text-end font-medium">Amount</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 text-end font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in requests" :key="row.id" class="border-b border-slate-100 align-top hover:bg-slate-50">
                        <td class="px-3 py-2 font-medium">{{ row.ref_no }}</td>
                        <td class="px-3 py-2">{{ fmtDate(row.created_at) }}</td>
                        <td class="px-3 py-2">{{ row.reseller?.name }}</td>
                        <td class="px-3 py-2">{{ row.type === 'deposit' ? 'Deposit' : 'Withdrawal' }}</td>
                        <td class="px-3 py-2">
                            {{ label(row.method) }}
                            <div class="text-xs text-slate-400">{{ row.account_details }}<span v-if="row.transaction_id"> · {{ row.transaction_id }}</span></div>
                            <div v-if="row.reseller_note" class="text-xs text-slate-500">Note: {{ row.reseller_note }}</div>
                            <div v-if="row.admin_note" class="text-xs text-slate-500">Your note: {{ row.admin_note }}</div>
                        </td>
                        <td class="px-3 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                        <td class="px-3 py-2"><StatusBadge :status="row.status" /></td>
                        <td class="px-3 py-2">
                            <div v-if="row.type === 'withdrawal' && row.status === 'pending'" class="flex justify-end gap-2">
                                <button type="button" class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs text-white" @click="openPay(row)">Mark paid</button>
                                <button type="button" class="rounded-md bg-red-600 px-2.5 py-1 text-xs text-white" @click="Object.assign(rej, { show: true, row, note: '', saving: false })">Reject</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="loaded && !requests.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">Nothing yet.</td></tr>
                </tbody>
            </table>
        </div>

        <Modal :show="dep.show" max-width="max-w-md" @close="dep.show = false">
            <form v-if="dep.child" class="space-y-3 p-5 text-sm" @submit.prevent="saveDeposit">
                <h2 class="text-base font-semibold text-slate-800">Cash received from {{ dep.child.name }}</h2>
                <p class="text-slate-500">Their balance: {{ signed(dep.child.balance) }}</p>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Amount ({{ cur() }})</label>
                    <input v-model="dep.amount" type="number" min="0" :step="moneyStep()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                        <select v-model="dep.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option v-for="m in METHODS" :key="m" :value="m">{{ label(m) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                        <input v-model="dep.transaction_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                </div>
                <input v-model="dep.note" placeholder="Note (optional)" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="dep.show = false">Close</button>
                    <button type="submit" :disabled="dep.saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Record</button>
                </div>
            </form>
        </Modal>

        <Modal :show="pay.show" max-width="max-w-md" @close="pay.show = false">
            <form v-if="pay.row" class="space-y-3 p-5 text-sm" @submit.prevent="savePay">
                <h2 class="text-base font-semibold text-slate-800">Pay withdrawal {{ pay.row.ref_no }}</h2>
                <p class="text-slate-500">{{ pay.row.reseller?.name }} · {{ fmtMoney(pay.row.amount) }} · {{ label(pay.row.method) }} {{ pay.row.account_details }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Paid by</label>
                        <select v-model="pay.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option v-for="m in METHODS" :key="m" :value="m">{{ label(m) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                        <input v-model="pay.transaction_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                </div>
                <input v-model="pay.note" placeholder="Note (optional)" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="pay.show = false">Close</button>
                    <button type="submit" :disabled="pay.saving" class="rounded-md bg-emerald-600 px-4 py-1.5 text-white disabled:opacity-50">Mark paid</button>
                </div>
            </form>
        </Modal>

        <Modal :show="rej.show" max-width="max-w-md" @close="rej.show = false">
            <form v-if="rej.row" class="space-y-3 p-5 text-sm" @submit.prevent="saveReject">
                <h2 class="text-base font-semibold text-slate-800">Reject withdrawal {{ rej.row.ref_no }}</h2>
                <p class="text-slate-500">{{ rej.row.reseller?.name }} · {{ fmtMoney(rej.row.amount) }}</p>
                <input v-model="rej.note" required minlength="3" maxlength="255" placeholder="Reason" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="rej.show = false">Close</button>
                    <button type="submit" :disabled="rej.saving" class="rounded-md bg-red-600 px-4 py-1.5 text-white disabled:opacity-50">Reject</button>
                </div>
            </form>
        </Modal>
    </PortalLayout>
</template>
