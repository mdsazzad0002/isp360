<script setup>
import { ref, reactive, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import Modal from '../Modal.vue';
import StatusBadge from './StatusBadge.vue';
import { money, fmtDate, today, promptReason, useApiError, fmtDateTime, moneyStep, invoiceTaxes } from '../../lib/isp';
import { printInvoice } from '../../lib/ispPrint';
import { useToast } from '../../lib/toast';

const props = defineProps({
    show: Boolean,
    invoiceId: { type: [Number, null], default: null },
    can: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'changed', 'edit-draft', 'receive']);
const page = usePage();
const toast = useToast();
const showError = useApiError();

const invoice = ref(null);
const balance = ref(0);
const busy = ref(false);
const noteForm = reactive({ open: false, type: 'credit', amount: '', reason: '', date: today() });

function load() {
    if (!props.invoiceId) return;
    invoice.value = null;
    axios.post('/isp/get-invoice', { id: props.invoiceId }).then((res) => {
        invoice.value = res.data.invoice;
        balance.value = res.data.customer_balance;
    });
}
watch(() => [props.show, props.invoiceId], ([s]) => s && load(), { immediate: true });

async function run(url, payload, reload = true) {
    busy.value = true;
    try {
        const res = await axios.post(url, payload);
        toast.success(res.data.message);
        emit('changed');
        if (reload) load();
        return true;
    } catch (err) {
        showError(err);
        return false;
    } finally {
        busy.value = false;
    }
}

async function voidInvoice() {
    const reason = await promptReason(`Void ${invoice.value.invoice_no}?`, {
        text: 'Payments on this invoice become advance credit. The billing period can be invoiced again.',
        confirmButtonText: 'Void invoice',
    });
    if (reason) run('/isp/invoice-void', { id: invoice.value.id, reason });
}

function issue() {
    run('/isp/invoice-issue', { id: invoice.value.id });
}

async function saveNote() {
    const ok = await run('/isp/invoice-note', { id: invoice.value.id, type: noteForm.type, amount: noteForm.amount, reason: noteForm.reason, date: noteForm.date });
    if (ok) Object.assign(noteForm, { open: false, amount: '', reason: '' });
}

function print() {
    printInvoice(invoice.value, page.props.company, balance.value);
}
</script>

<template>
    <Modal :show="show" max-width="max-w-3xl" @close="emit('close')">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-base font-semibold text-slate-800">
                Invoice {{ invoice?.invoice_no }}
                <StatusBadge v-if="invoice" :status="invoice.status" class="ms-2" />
            </h2>
            <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div v-if="!invoice" class="p-8 text-center text-sm text-slate-400">Loading...</div>
        <div v-else class="max-h-[75vh] space-y-4 overflow-y-auto p-4 text-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <div class="font-medium text-slate-800">{{ invoice.customer?.name }}</div>
                    <div class="text-slate-500">{{ invoice.customer?.code }} · {{ invoice.customer?.phone }}</div>
                    <div v-if="invoice.connection" class="text-slate-500">Connection {{ invoice.connection.code }} <span v-if="invoice.connection.pppoe_username">/ {{ invoice.connection.pppoe_username }}</span></div>
                </div>
                <div class="text-slate-600 sm:text-end">
                    <div>Invoice date: {{ fmtDate(invoice.invoice_date) }}</div>
                    <div>Due date: <strong>{{ fmtDate(invoice.due_date) }}</strong></div>
                    <div v-if="invoice.period_start">Period: {{ fmtDateTime(invoice.period_start) }} – {{ fmtDateTime(invoice.period_end) }}</div>
                    <div class="text-xs text-slate-400">{{ invoice.source === 'auto' ? 'Auto generated' : 'Manual' }} · by {{ invoice.created_by?.name || 'System' }}</div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-1.5 font-medium">Description</th>
                            <th class="px-2 py-1.5 text-end font-medium">Price</th>
                            <th class="px-2 py-1.5 text-end font-medium">Qty</th>
                            <th class="px-2 py-1.5 text-end font-medium">Disc.</th>
                            <th class="px-2 py-1.5 text-end font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in invoice.items" :key="item.id" class="border-b border-slate-100">
                            <td class="px-2 py-1.5">{{ item.description }}</td>
                            <td class="px-2 py-1.5 text-end">{{ money(item.unit_price) }}</td>
                            <td class="px-2 py-1.5 text-end">{{ Number(item.quantity) }}</td>
                            <td class="px-2 py-1.5 text-end">{{ money(item.discount) }}</td>
                            <td class="px-2 py-1.5 text-end">{{ money(item.total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="text-slate-700">
                        <tr><td colspan="4" class="px-2 py-1 text-end">Subtotal</td><td class="px-2 py-1 text-end">{{ money(invoice.subtotal) }}</td></tr>
                        <tr v-if="Number(invoice.discount)"><td colspan="4" class="px-2 py-1 text-end">Discount</td><td class="px-2 py-1 text-end">- {{ money(invoice.discount) }}</td></tr>
                        <template v-if="!invoice.tax_inclusive">
                            <tr v-for="t in invoiceTaxes(invoice)" :key="t.label"><td colspan="4" class="px-2 py-1 text-end">{{ t.label }}</td><td class="px-2 py-1 text-end">{{ money(t.amount) }}</td></tr>
                        </template>
                        <tr v-if="Number(invoice.adjustment)"><td colspan="4" class="px-2 py-1 text-end">Credit / debit notes</td><td class="px-2 py-1 text-end">{{ money(invoice.adjustment) }}</td></tr>
                        <tr class="font-semibold"><td colspan="4" class="px-2 py-1 text-end">Total</td><td class="px-2 py-1 text-end">{{ money(invoice.total) }}</td></tr>
                        <template v-if="invoice.tax_inclusive">
                            <tr v-for="t in invoiceTaxes(invoice)" :key="t.label" class="text-slate-500"><td colspan="4" class="px-2 py-1 text-end">Includes {{ t.label }}</td><td class="px-2 py-1 text-end">{{ money(t.amount) }}</td></tr>
                        </template>
                        <tr><td colspan="4" class="px-2 py-1 text-end">Paid</td><td class="px-2 py-1 text-end text-emerald-700">{{ money(invoice.paid) }}</td></tr>
                        <tr class="font-semibold"><td colspan="4" class="px-2 py-1 text-end">Due</td><td class="px-2 py-1 text-end text-red-600">{{ money(invoice.due) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="invoice.allocations?.length">
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Payments applied</h3>
                <div v-for="a in invoice.allocations" :key="a.id" class="flex justify-between border-b border-slate-100 py-1" :class="a.status === 'reversed' ? 'text-slate-400 line-through' : ''">
                    <span>{{ a.payment?.receipt_no }} · {{ fmtDate(a.payment?.payment_date) }} · {{ a.payment?.method }}</span>
                    <span>{{ money(a.amount) }}</span>
                </div>
            </div>

            <div v-if="invoice.notes?.length">
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Credit / debit notes</h3>
                <div v-for="n in invoice.notes" :key="n.id" class="flex justify-between border-b border-slate-100 py-1">
                    <span>{{ n.note_no }} · {{ n.type }} · {{ n.reason }} <span class="text-xs text-slate-400">({{ n.created_by?.name }})</span></span>
                    <span :class="n.type === 'credit' ? 'text-emerald-700' : 'text-red-600'">{{ n.type === 'credit' ? '-' : '+' }}{{ money(n.amount) }}</span>
                </div>
            </div>

            <div v-if="invoice.status === 'void' || invoice.status === 'cancelled'" class="rounded-md border border-red-200 bg-red-50 p-2 text-red-700">
                {{ invoice.status === 'void' ? 'Voided' : 'Cancelled' }}: {{ invoice.void_reason }}
            </div>

            <div v-if="noteForm.open" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <select v-model="noteForm.type" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="credit">Credit note (reduce)</option>
                        <option value="debit">Debit note (add charge)</option>
                    </select>
                    <input v-model="noteForm.amount" type="number" min="0" :step="moneyStep()" placeholder="Amount" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    <input v-model="noteForm.date" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    <input v-model="noteForm.reason" type="text" placeholder="Reason (required)" class="col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm sm:col-span-1" />
                </div>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="noteForm.open = false">Cancel</button>
                    <button type="button" :disabled="busy" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white disabled:opacity-50" @click="saveNote">Save note</button>
                </div>
            </div>
        </div>
        <div v-if="invoice" class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3">
            <button v-if="invoice.status === 'draft' && can.create" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="emit('edit-draft', invoice)"><i class="bi bi-pen"></i> Edit draft</button>
            <button v-if="invoice.status === 'draft' && can.create" type="button" :disabled="busy" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white disabled:opacity-50" @click="issue"><i class="bi bi-send"></i> Issue</button>
            <button v-if="can.note && ['issued', 'partially_paid', 'paid', 'overdue'].includes(invoice.status) && !noteForm.open" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="noteForm.open = true"><i class="bi bi-journal-plus"></i> Credit / Debit note</button>
            <button v-if="can.void && !['void', 'cancelled'].includes(invoice.status)" type="button" :disabled="busy" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50" @click="voidInvoice"><i class="bi bi-x-octagon"></i> {{ invoice.status === 'draft' ? 'Cancel draft' : 'Void' }}</button>
            <button v-if="can.payment && Number(invoice.due) > 0 && invoice.status !== 'draft'" type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-700" @click="emit('receive', invoice)"><i class="bi bi-cash"></i> Receive payment</button>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="print"><i class="bi bi-printer"></i> Print</button>
        </div>
    </Modal>
</template>
