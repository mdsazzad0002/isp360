<script setup>
import { ref, reactive, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import Modal from '../Modal.vue';
import SearchSelect from '../SearchSelect.vue';
import StatusBadge from './StatusBadge.vue';
import { money, fmtDate, label, today, PAYMENT_METHODS, promptReason, useApiError } from '../../lib/isp';
import { printReceipt } from '../../lib/ispPrint';
import { useToast } from '../../lib/toast';

const props = defineProps({
    show: Boolean,
    paymentId: { type: [Number, null], default: null },
    can: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'changed']);
const page = usePage();
const toast = useToast();
const showError = useApiError();

const payment = ref(null);
const balance = ref(0);
const busy = ref(false);
const banks = ref([]);
const refund = reactive({ open: false, amount: '', method: 'cash', bank: null, refund_date: today(), reason: '' });
const move = reactive({ allocation: null, invoices: [], target: null, reason: '' });

function load() {
    if (!props.paymentId) return;
    payment.value = null;
    axios.post('/isp/get-payment', { id: props.paymentId }).then((res) => {
        payment.value = res.data.payment;
        balance.value = res.data.customer_balance;
    });
}
watch(() => [props.show, props.paymentId], ([s]) => s && load(), { immediate: true });

async function post(url, data) {
    busy.value = true;
    try {
        const res = await axios.post(url, data);
        toast.success(res.data.message);
        emit('changed');
        load();
        return true;
    } catch (err) {
        showError(err);
        return false;
    } finally {
        busy.value = false;
    }
}

async function reverse() {
    const reason = await promptReason(`Reverse ${payment.value.receipt_no}?`, {
        text: 'The payment stays on record as REVERSED, its invoices become due again and the cash/bank entry is removed. Enter the correct payment afterwards if needed.',
        confirmButtonText: 'Reverse payment',
    });
    if (reason) post('/isp/payment-reverse', { id: payment.value.id, reason });
}

function openRefund() {
    if (!banks.value.length) axios.post('/get-bank').then((r) => (banks.value = r.data));
    Object.assign(refund, { open: true, amount: payment.value.unallocated, reason: '' });
}

async function saveRefund() {
    const ok = await post('/isp/payment-refund', {
        id: payment.value.id, amount: refund.amount, method: refund.method, bank_id: refund.method === 'cash' ? null : refund.bank?.id,
        refund_date: refund.refund_date, reason: refund.reason,
    });
    if (ok) refund.open = false;
}

async function openMove(allocation) {
    const res = await axios.post('/isp/get-customer-dues', { customerId: payment.value.customer_id });
    Object.assign(move, { allocation, invoices: res.data.invoices.filter((i) => i.id !== allocation.invoice_id).map((i) => ({ ...i, display: `${i.invoice_no} — due ${money(i.due)}` })), target: null, reason: '' });
}

async function saveMove() {
    if (!move.reason || move.reason.length < 3) return toast.error('Enter a reason');
    const ok = await post('/isp/payment-reallocate', { allocation_id: move.allocation.id, invoice_id: move.target?.id || null, reason: move.reason });
    if (ok) move.allocation = null;
}

function print() {
    printReceipt(payment.value, page.props.company, balance.value);
}
</script>

<template>
    <Modal :show="show" max-width="max-w-2xl" @close="emit('close')">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-base font-semibold text-slate-800">Payment {{ payment?.receipt_no }} <StatusBadge v-if="payment" :status="payment.status" class="ml-2" /></h2>
            <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div v-if="!payment" class="p-8 text-center text-sm text-slate-400">Loading...</div>
        <div v-else class="max-h-[75vh] space-y-4 overflow-y-auto p-4 text-sm">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <div class="font-medium text-slate-800">{{ payment.customer?.name }}</div>
                    <div class="text-slate-500">{{ payment.customer?.code }} · {{ payment.customer?.phone }}</div>
                </div>
                <div class="text-right">
                    <div class="text-lg font-semibold text-slate-800">Tk {{ money(payment.amount) }}</div>
                    <div class="text-slate-500">{{ fmtDate(payment.payment_date) }} · {{ label(payment.method) }}<span v-if="payment.bank"> ({{ payment.bank.name }})</span></div>
                    <div v-if="payment.transaction_id" class="text-slate-500">TrxID {{ payment.transaction_id }}</div>
                    <div class="text-xs text-slate-400">Received by {{ payment.received_by?.name || 'System' }} · {{ payment.source }}</div>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Applied</div><div class="font-semibold">{{ money(payment.allocated_amount) }}</div></div>
                <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Refunded</div><div class="font-semibold">{{ money(payment.refunded_amount) }}</div></div>
                <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Advance (unapplied)</div><div class="font-semibold text-emerald-700">{{ money(payment.unallocated) }}</div></div>
            </div>

            <div>
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Applied to invoices</h3>
                <div v-for="a in payment.allocations" :key="a.id" class="flex items-center justify-between border-b border-slate-100 py-1.5" :class="a.status === 'reversed' ? 'text-slate-400' : ''">
                    <span :class="a.status === 'reversed' ? 'line-through' : ''">{{ a.invoice?.invoice_no }} <span v-if="a.invoice?.period_start" class="text-xs text-slate-400">{{ fmtDate(a.invoice.period_start) }} – {{ fmtDate(a.invoice.period_end) }}</span></span>
                    <span class="flex items-center gap-3">
                        <span :class="a.status === 'reversed' ? 'line-through' : ''">{{ money(a.amount) }}</span>
                        <button v-if="a.status === 'active' && can.reverse" type="button" class="text-xs text-brand-600 hover:underline" @click="openMove(a)">Move / remove</button>
                        <span v-if="a.status === 'reversed'" class="text-[11px]" :title="a.reversal_reason">reversed</span>
                    </span>
                </div>
                <div v-if="!payment.allocations?.length" class="py-2 text-slate-400">Not applied to any invoice (advance credit).</div>
            </div>

            <div v-if="move.allocation" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="mb-2 text-xs text-slate-600">Move {{ money(move.allocation.amount) }} from {{ move.allocation.invoice?.invoice_no }} to another invoice, or leave empty to keep it as advance credit.</div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <SearchSelect :options="move.invoices" v-model="move.target" label="display" placeholder="Target invoice (optional)" />
                    <input v-model="move.reason" type="text" placeholder="Reason (required)" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                </div>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="move.allocation = null">Cancel</button>
                    <button type="button" :disabled="busy" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white disabled:opacity-50" @click="saveMove">Save</button>
                </div>
            </div>

            <div v-if="payment.refunds?.length">
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Refunds</h3>
                <div v-for="r in payment.refunds" :key="r.id" class="flex justify-between border-b border-slate-100 py-1">
                    <span>{{ r.refund_no }} · {{ fmtDate(r.refund_date) }} · {{ r.reason }}</span><span>{{ money(r.amount) }}</span>
                </div>
            </div>

            <div v-if="refund.open" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <input v-model="refund.amount" type="number" min="0" :max="payment.unallocated" step="0.01" placeholder="Amount" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    <select v-model="refund.method" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                        <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <input v-model="refund.refund_date" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    <input v-model="refund.reason" type="text" placeholder="Reason (required)" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    <div v-if="refund.method !== 'cash'" class="col-span-2 sm:col-span-4">
                        <SearchSelect :options="banks" v-model="refund.bank" label="display_name" placeholder="Paid from account" />
                    </div>
                </div>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="refund.open = false">Cancel</button>
                    <button type="button" :disabled="busy" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white disabled:opacity-50" @click="saveRefund">Save refund</button>
                </div>
            </div>

            <div v-if="payment.status === 'reversed'" class="rounded-md border border-red-200 bg-red-50 p-2 text-red-700">Reversed {{ fmtDate(payment.reversed_at) }}: {{ payment.reversal_reason }}</div>
        </div>
        <div v-if="payment" class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3">
            <button v-if="can.refund && Number(payment.unallocated) > 0 && !refund.open" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="openRefund"><i class="bi bi-arrow-return-left"></i> Refund advance</button>
            <button v-if="can.reverse && payment.status === 'completed'" type="button" :disabled="busy" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50" @click="reverse"><i class="bi bi-arrow-counterclockwise"></i> Reverse</button>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="print"><i class="bi bi-printer"></i> Print receipt</button>
        </div>
    </Modal>
</template>
