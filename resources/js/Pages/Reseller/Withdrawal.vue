<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { money, fmtDate, label, useApiError, cur, moneyStep } from '../../lib/isp';

const props = defineProps({
    reseller: { type: Object, required: true },
});

const toast = useToast();
const showError = useApiError();

const wallet = ref({});
const rows = ref([]);
const saving = ref(false);
function blank() {
    return { amount: '', method: 'bkash', account_details: props.reseller.phone || '', note: '' };
}
const form = reactive(blank());

function load() {
    axios.post('/reseller/get-withdrawals').then((res) => {
        wallet.value = res.data.wallet;
        rows.value = res.data.transactions;
    });
}

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/reseller/withdrawal', { ...form });
        toast.success(res.data.message);
        Object.assign(form, blank());
        load();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

async function cancel(row) {
    if (!(await confirmDialog({ title: `Cancel request ${row.ref_no}?` }))) return;
    try {
        const res = await axios.post('/reseller/withdrawal-cancel', { id: row.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}

onMounted(load);
</script>

<template>
    <PortalLayout :user-name="reseller.name" logout-url="/reseller/logout">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">Wallet & Withdrawal</h1>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Earned</div>
                <div class="text-lg font-semibold text-slate-800">{{ money(wallet.earned) }}</div>
                <div class="text-[11px] text-slate-400">from paid bills</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Cash in my hand</div>
                <div class="text-lg font-semibold text-amber-700">{{ money(wallet.collected - wallet.deposits) }}</div>
                <div class="text-[11px] text-slate-400">collected {{ money(wallet.collected) }} · deposited {{ money(wallet.deposits) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Withdrawn</div>
                <div class="text-lg font-semibold text-slate-800">{{ money(wallet.withdrawn) }}</div>
                <div class="text-[11px] text-slate-400">pending {{ money(wallet.pending) }}</div>
            </div>
            <div class="rounded-lg border p-3 shadow-sm" :class="wallet.balance < 0 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'">
                <div class="text-[11px] uppercase text-slate-500">{{ wallet.balance < 0 ? `I owe ${wallet.settles_with || 'the company'}` : 'Balance' }}</div>
                <div class="text-lg font-semibold" :class="wallet.balance < 0 ? 'text-red-600' : 'text-emerald-700'">{{ money(Math.abs(wallet.balance || 0)) }}</div>
            </div>
            <div class="col-span-2 rounded-lg border border-brand-200 bg-white p-3 shadow-sm md:col-span-1">
                <div class="text-[11px] uppercase text-slate-400">Available to withdraw</div>
                <div class="text-lg font-semibold text-brand-600">{{ money(wallet.available) }}</div>
                <div class="text-[11px] text-slate-400">unpaid bills: {{ money(wallet.expected - wallet.earned) }} more to earn</div>
            </div>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">Withdrawal request</h2>
            <p class="mb-3 text-xs text-slate-500">
                Balance = your earning on paid bills (with your sub-resellers') − cash collected (by you and your sub-resellers) + cash you deposited − money already withdrawn. {{ wallet.settles_with || 'The company' }} pays the request and marks it paid.
            </p>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-4" @submit.prevent="save">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Amount ({{ cur() }})</label>
                    <input v-model="form.amount" type="number" min="1" :max="wallet.available" :step="moneyStep()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Receive by</label>
                    <select v-model="form.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="bkash">bKash</option>
                        <option value="nagad">Nagad</option>
                        <option value="rocket">Rocket</option>
                        <option value="bank">Bank transfer</option>
                        <option value="cash">Cash (from office)</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ form.method === 'bank' ? 'Bank, branch, account name & number' : 'Account number' }}</label>
                    <input v-model="form.account_details" :required="form.method !== 'cash'" :disabled="form.method === 'cash'" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:bg-slate-100" />
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                    <input v-model="form.note" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="flex items-end justify-end">
                    <button type="submit" :disabled="saving || !(wallet.available > 0)" class="w-full rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">Send request</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-3 text-sm font-semibold text-slate-700">History</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Ref</th>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Method</th>
                            <th class="px-3 py-2 text-end font-medium">Amount</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Note</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 align-top hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium">{{ row.ref_no }}</td>
                            <td class="px-3 py-2">{{ fmtDate(row.created_at) }}<div v-if="row.processed_at" class="text-xs text-slate-400">done {{ fmtDate(row.processed_at) }}</div></td>
                            <td class="px-3 py-2">{{ row.type === 'deposit' ? `Deposit to ${wallet.settles_with || 'company'}` : 'Withdrawal' }}</td>
                            <td class="px-3 py-2">{{ label(row.method) }}<div class="text-xs text-slate-400">{{ row.account_details }}<span v-if="row.transaction_id"> · {{ row.transaction_id }}</span></div></td>
                            <td class="px-3 py-2 text-end font-medium" :class="row.type === 'deposit' ? 'text-emerald-700' : ''">{{ money(row.amount) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="row.status" /></td>
                            <td class="px-3 py-2 text-xs text-slate-500">
                                <div v-if="row.reseller_note">Me: {{ row.reseller_note }}</div>
                                <div v-if="row.admin_note">Company: {{ row.admin_note }}</div>
                            </td>
                            <td class="px-3 py-2 text-end">
                                <button v-if="row.type === 'withdrawal' && row.status === 'pending'" type="button" class="text-xs text-red-600 hover:underline" @click="cancel(row)">Cancel</button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No withdrawals yet</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>
