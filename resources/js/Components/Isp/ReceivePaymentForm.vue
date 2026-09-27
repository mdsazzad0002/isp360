<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import SearchSelect from '../SearchSelect.vue';
import CustomerPicker from './CustomerPicker.vue';
import StatusBadge from './StatusBadge.vue';
import { money, fmtDate, today, PAYMENT_METHODS, useApiError, moneyStep, roundMoney } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// Bill collection form. Allocation: "auto" pays the oldest invoices first; "manual" lets
// the collector split the amount per invoice. Anything left over stays as advance credit.
const props = defineProps({
    customerId: { type: [Number, null], default: null },
    invoiceId: { type: [Number, null], default: null },
});
const emit = defineEmits(['saved']);
const toast = useToast();
const showError = useApiError();

const customer = ref(null);
const dues = ref({ balance: 0, advance: 0, invoices: [] });
const banks = ref([]);
const bank = ref(null);
const saving = ref(false);
const mode = ref('auto');
const alloc = reactive({});
const form = reactive({ amount: '', method: 'cash', payment_date: today(), transaction_id: '', reference: '', notes: '' });

const allocatedTotal = computed(() => Object.values(alloc).reduce((s, v) => s + Number(v || 0), 0));
const leftover = computed(() => Number(form.amount || 0) - (mode.value === 'manual' ? allocatedTotal.value : 0));

function loadDues() {
    if (!customer.value) {
        dues.value = { balance: 0, advance: 0, invoices: [] };
        return;
    }
    axios.post('/isp/get-customer-dues', { customerId: customer.value.id }).then((res) => {
        dues.value = res.data;
        Object.keys(alloc).forEach((k) => delete alloc[k]);
        if (props.invoiceId) {
            const inv = res.data.invoices.find((i) => i.id === props.invoiceId);
            if (inv) {
                mode.value = 'manual';
                alloc[inv.id] = Number(inv.due);
                form.amount = Number(inv.due);
                return;
            }
        }
        if (!form.amount && res.data.balance > 0) form.amount = Number(res.data.balance);
    });
}
watch(customer, () => {
    form.amount = '';
    loadDues();
});

function fillAlloc() {
    let remaining = Number(form.amount || 0);
    dues.value.invoices.forEach((inv) => {
        const take = Math.min(remaining, Number(inv.due));
        alloc[inv.id] = take > 0 ? roundMoney(take) : '';
        remaining -= take;
    });
}

async function save() {
    if (!customer.value) return toast.error('Select a customer');
    if (mode.value === 'manual' && allocatedTotal.value > Number(form.amount || 0) + 0.001) return toast.error('Allocated total is more than the amount');
    saving.value = true;
    try {
        const res = await axios.post('/isp/payment', {
            ...form,
            customer_id: customer.value.id,
            bank_id: form.method === 'cash' ? null : bank.value?.id,
            allocations: mode.value === 'manual' ? { ...alloc } : null,
        });
        toast.success(res.data.message);
        Object.assign(form, { amount: '', transaction_id: '', reference: '', notes: '' });
        loadDues();
        emit('saved', res.data.id);
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    axios.post('/get-bank').then((res) => (banks.value = res.data));
});
</script>

<template>
    <form @submit.prevent="save" class="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <div class="space-y-3 lg:col-span-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
                <CustomerPicker v-model="customer" :preselect-id="customerId" :disabled="!!customerId" />
            </div>
            <div v-if="customer" class="grid grid-cols-2 gap-2 text-center">
                <div class="rounded-md border border-slate-200 p-2">
                    <div class="text-[11px] uppercase text-slate-400">Current due</div>
                    <div class="font-semibold" :class="dues.balance > 0 ? 'text-red-600' : 'text-slate-800'">{{ money(Math.max(0, dues.balance)) }}</div>
                </div>
                <div class="rounded-md border border-slate-200 p-2">
                    <div class="text-[11px] uppercase text-slate-400">Advance credit</div>
                    <div class="font-semibold text-emerald-700">{{ money(dues.advance) }}</div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Amount</label>
                    <input v-model="form.amount" type="number" min="0" :step="moneyStep()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                    <input v-model="form.payment_date" type="date" :max="today()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                    <select v-model="form.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                    <input v-model="form.transaction_id" type="text" :required="['bkash', 'nagad', 'rocket'].includes(form.method)" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
            </div>
            <div v-if="form.method !== 'cash'">
                <label class="mb-1 block text-xs font-medium text-slate-600">Received into account</label>
                <SearchSelect :options="banks" v-model="bank" label="display_name" placeholder="Bank / bKash / Nagad account" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Reference</label>
                    <input v-model="form.reference" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                    <input v-model="form.notes" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
            </div>
        </div>

        <div class="lg:col-span-3">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open invoices</span>
                <div class="flex items-center gap-3 text-sm">
                    <label class="flex items-center gap-1"><input type="radio" value="auto" v-model="mode" /> Oldest first</label>
                    <label class="flex items-center gap-1"><input type="radio" value="manual" v-model="mode" @change="fillAlloc" /> Choose invoices</label>
                </div>
            </div>
            <div class="max-h-72 overflow-y-auto rounded-md border border-slate-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-1.5 font-medium">Invoice</th>
                            <th class="px-2 py-1.5 font-medium">Due date</th>
                            <th class="px-2 py-1.5 text-end font-medium">Due</th>
                            <th v-if="mode === 'manual'" class="px-2 py-1.5 text-end font-medium">Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="inv in dues.invoices" :key="inv.id" class="border-b border-slate-100">
                            <td class="px-2 py-1.5">{{ inv.invoice_no }} <StatusBadge :status="inv.status" /></td>
                            <td class="px-2 py-1.5">{{ fmtDate(inv.due_date) }}</td>
                            <td class="px-2 py-1.5 text-end">{{ money(inv.due) }}</td>
                            <td v-if="mode === 'manual'" class="px-2 py-1 text-end">
                                <input v-model="alloc[inv.id]" type="number" min="0" :max="inv.due" :step="moneyStep()" class="w-28 rounded border border-slate-300 px-2 py-1 text-end text-sm" />
                            </td>
                        </tr>
                        <tr v-if="!dues.invoices.length">
                            <td :colspan="mode === 'manual' ? 4 : 3" class="px-2 py-6 text-center text-slate-400">{{ customer ? 'No open invoices — the payment will be kept as advance credit' : 'Select a customer' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-2 flex items-center justify-between text-sm">
                <span class="text-slate-500">
                    <template v-if="mode === 'manual'">Allocated {{ money(allocatedTotal) }} · </template>
                    <span v-if="leftover > 0.001">Advance credit after this payment: <strong class="text-emerald-700">{{ money(mode === 'manual' ? leftover : Math.max(0, leftover - Math.max(0, dues.balance))) }}</strong></span>
                </span>
                <button type="submit" :disabled="saving || !customer" class="rounded-md bg-brand-500 px-5 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    <i class="bi bi-check2-circle"></i> Receive payment
                </button>
            </div>
        </div>
    </form>
</template>
