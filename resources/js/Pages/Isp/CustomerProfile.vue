<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import ConnectionPanel from '../../Components/Isp/ConnectionPanel.vue';
import ConnectionFormModal from '../../Components/Isp/ConnectionFormModal.vue';
import InvoiceDetailModal from '../../Components/Isp/InvoiceDetailModal.vue';
import ManualInvoiceModal from '../../Components/Isp/ManualInvoiceModal.vue';
import PaymentDetailModal from '../../Components/Isp/PaymentDetailModal.vue';
import ReceivePaymentForm from '../../Components/Isp/ReceivePaymentForm.vue';
import LedgerStatement from '../../Components/Isp/LedgerStatement.vue';
import CompliancePanel from '../../Components/Isp/CompliancePanel.vue';
import TerminalOffcanvas from '../../Components/Isp/TerminalOffcanvas.vue';
import SyncBadge from '../../Components/Isp/SyncBadge.vue';
import { money, fmtDate, label, expiryClass, fmtDateTime, fmtMoney, moneyStep, PAYMENT_METHODS, promptReason, useApiError } from '../../lib/isp';
import { useToast } from '../../lib/toast';

defineOptions({ layout: AppLayout });
const props = defineProps({ customerId: { type: Number, required: true }, can: { type: Object, default: () => ({}) } });

const data = ref(null);
const toast = useToast();
const showError = useApiError();
// ?terminal=<connection id> opens the terminal on that connection
const terminalParam = Number(new URLSearchParams(window.location.search).get('terminal')) || null;
const tab = ref('connections');
const terminal = reactive({ show: !!(terminalParam && props.can.connection), id: terminalParam });

function openTerminal(c) {
    Object.assign(terminal, { show: true, id: c.id });
}
async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`Copied ${text}`);
    } catch {
        toast.error('Copy is blocked by the browser');
    }
}
const ledgerRef = ref(null);
const connPanel = reactive({ show: false, id: null });
const connForm = reactive({ show: false, connection: null });
const invDetail = reactive({ show: false, id: null });
const manual = reactive({ show: false, draft: null });
const payDetail = reactive({ show: false, id: null });
const receive = reactive({ show: false, invoiceId: null });

const invoiceCan = { create: props.can.invoiceCreate, void: props.can.invoiceVoid, note: props.can.billingNote, payment: props.can.payment };
const paymentCan = { reverse: props.can.paymentReverse, refund: props.can.refund };

function load() {
    axios.post('/isp/get-customer-profile', { id: props.customerId }).then((res) => (data.value = res.data));
    ledgerRef.value?.load();
}

function openReceive(invoice = null) {
    invDetail.show = false;
    Object.assign(receive, { show: true, invoiceId: invoice?.id ?? null });
}

// security deposits
const depositForm = reactive({ amount: '', method: 'cash', bank_id: null, connection_id: null, notes: '' });
const banks = ref([]);
async function depositAction(url, payload) {
    try {
        toast.success((await axios.post(url, payload)).data.message);
        Object.assign(depositForm, { amount: '', notes: '' });
        load();
        return true;
    } catch (err) {
        showError(err);
        return false;
    }
}
async function applyDeposit(d) {
    const reason = await promptReason(`Apply ${money(d.held)} of ${d.deposit_no} to the customer's dues?`, { confirmButtonText: 'Apply', placeholder: 'Note (optional)' });
    if (reason !== null) depositAction('/isp/deposit-apply', { id: d.id, reason });
}
async function refundDeposit(d) {
    const reason = await promptReason(`Refund ${money(d.held)} of ${d.deposit_no} in cash?`, { confirmButtonText: 'Refund', text: 'Paid back in cash (cash book).', placeholder: 'Reason (required)' });
    if (reason) depositAction('/isp/deposit-refund', { id: d.id, method: 'cash', reason });
}

onMounted(() => {
    load();
    axios.post('/get-bank').then((r) => (banks.value = Array.isArray(r.data) ? r.data : r.data?.data || [])).catch(() => {});
});
</script>

<template>
    <div class="space-y-3 p-4">
        <div v-if="!data" class="p-8 text-center text-sm text-slate-400">Loading...</div>
        <template v-else>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <img :src="data.customer.image ? '/' + data.customer.image : '/noImage.jpg'" class="h-14 w-14 rounded-md border border-slate-200 object-cover" />
                        <div>
                            <h1 class="text-lg font-semibold text-slate-800">{{ data.customer.name }}
                                <span class="ms-1 rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="data.customer.account_status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-100 text-slate-600'">{{ label(data.customer.account_status) }}</span>
                            </h1>
                            <div class="text-sm text-slate-500">{{ data.customer.code }} · {{ data.customer.phone }}<span v-if="data.customer.email"> · {{ data.customer.email }}</span></div>
                            <div class="text-sm text-slate-500">
                                {{ [data.customer.zone?.name, data.customer.area?.name, data.customer.box?.name].filter(Boolean).join(' → ') || 'No location set' }}
                                <span v-if="data.customer.address"> · {{ data.customer.address }}</span>
                            </div>
                            <div v-if="data.customer.nid" class="text-xs text-slate-400">NID {{ data.customer.nid }}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="can.payment" type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-700" @click="openReceive()"><i class="bi bi-cash"></i> Receive payment</button>
                        <button v-if="can.connection" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="Object.assign(connForm, { show: true, connection: null })"><i class="bi bi-ethernet"></i> New connection</button>
                        <button v-if="can.invoiceCreate" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="Object.assign(manual, { show: true, draft: null })"><i class="bi bi-receipt"></i> Manual invoice</button>
                        <Link href="/customer" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm"><i class="bi bi-pen"></i> Edit profile</Link>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Current due</div><div class="text-lg font-semibold" :class="data.summary.balance > 0 ? 'text-red-600' : 'text-slate-800'">{{ money(Math.max(0, data.summary.balance)) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Overdue</div><div class="text-lg font-semibold text-red-600">{{ money(data.summary.overdue) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Advance credit</div><div class="text-lg font-semibold text-emerald-700">{{ money(data.summary.advance) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Total billed</div><div class="text-lg font-semibold text-slate-800">{{ money(data.summary.total_billed) }}</div></div>
                    <div v-if="data.summary.deposit_held > 0" class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Deposit held</div><div class="text-lg font-semibold text-indigo-700">{{ money(data.summary.deposit_held) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Total paid</div><div class="text-lg font-semibold text-slate-800">{{ money(data.summary.total_paid) }}</div></div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-2">
                    <button v-for="t in [['connections', 'Connections', data.connections.length], ['invoices', 'Invoices', data.invoices.length], ['payments', 'Payments', data.payments.length], ['deposits', 'Deposits', data.deposits.length], ['compliance', 'Compliance', null], ['ledger', 'Ledger', null]]" :key="t[0]" type="button"
                        class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm" :class="tab === t[0] ? 'border-brand-500 font-medium text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'" @click="tab = t[0]">
                        {{ t[1] }} <span v-if="t[2] !== null" class="text-xs text-slate-400">({{ t[2] }})</span>
                    </button>
                </div>

                <div class="overflow-x-auto p-3">
                    <table v-if="tab === 'connections'" class="w-full text-sm">
                        <thead><tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Code</th><th class="px-2 py-2 font-medium">Package</th><th class="px-2 py-2 font-medium">Type / User</th><th class="px-2 py-2 font-medium">Box</th><th class="px-2 py-2 font-medium">Expire date</th><th class="px-2 py-2 font-medium">Status</th><th class="px-2 py-2 font-medium">Router sync</th><th v-if="can.connection" class="px-2 py-2"></th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="c in data.connections" :key="c.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="Object.assign(connPanel, { show: true, id: c.id })">
                                <td class="px-2 py-2 font-medium text-brand-600">{{ c.code }}</td>
                                <td class="px-2 py-2">{{ c.package?.name }} <span class="text-xs text-slate-400">{{ fmtMoney(c.package?.price) }}</span></td>
                                <td class="px-2 py-2">
                                    {{ label(c.connection_type) }} <span class="font-mono text-xs text-slate-500">{{ c.pppoe_username || c.static_ip }}</span>
                                    <button v-if="c.pppoe_username || c.static_ip" type="button" class="ms-1 text-slate-400 hover:text-brand-600" title="Copy" @click.stop="copyText(c.pppoe_username || c.static_ip)"><i class="bi bi-copy text-xs"></i></button>
                                </td>
                                <td class="px-2 py-2">{{ c.box?.name || '—' }}</td>
                                <td class="px-2 py-2" :class="expiryClass(c.expire_at)">{{ fmtDateTime(c.expire_at) || 'Unpaid' }}</td>
                                <td class="px-2 py-2"><StatusBadge :status="c.status" /> <span v-if="c.suspension_reason" class="text-xs text-amber-700">{{ c.suspension_reason }}</span></td>
                                <td class="px-2 py-2"><SyncBadge :connection="c" /></td>
                                <td v-if="can.connection" class="px-2 py-2 text-end">
                                    <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-600 hover:border-slate-800 hover:bg-slate-900 hover:text-emerald-400" title="Open terminal for this connection" @click.stop="openTerminal(c)"><i class="bi bi-terminal"></i> Test</button>
                                </td>
                            </tr>
                            <tr v-if="!data.connections.length"><td colspan="8" class="px-2 py-6 text-center text-slate-400">No connections yet</td></tr>
                        </tbody>
                    </table>

                    <table v-if="tab === 'invoices'" class="w-full text-sm">
                        <thead><tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Invoice</th><th class="px-2 py-2 font-medium">Period</th><th class="px-2 py-2 font-medium">Due date</th><th class="px-2 py-2 text-end font-medium">Total</th><th class="px-2 py-2 text-end font-medium">Paid</th><th class="px-2 py-2 text-end font-medium">Due</th><th class="px-2 py-2 font-medium">Status</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="i in data.invoices" :key="i.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="Object.assign(invDetail, { show: true, id: i.id })">
                                <td class="px-2 py-2 font-medium text-brand-600">{{ i.invoice_no }}</td>
                                <td class="px-2 py-2 text-xs">{{ i.period_start ? `${fmtDateTime(i.period_start)} – ${fmtDateTime(i.period_end)}` : fmtDate(i.invoice_date) }}</td>
                                <td class="px-2 py-2">{{ fmtDate(i.due_date) }}</td>
                                <td class="px-2 py-2 text-end">{{ money(i.total) }}</td>
                                <td class="px-2 py-2 text-end text-emerald-700">{{ money(i.paid) }}</td>
                                <td class="px-2 py-2 text-end font-medium" :class="Number(i.due) > 0 ? 'text-red-600' : ''">{{ money(i.due) }}</td>
                                <td class="px-2 py-2"><StatusBadge :status="i.status" /></td>
                            </tr>
                            <tr v-if="!data.invoices.length"><td colspan="7" class="px-2 py-6 text-center text-slate-400">No invoices yet</td></tr>
                        </tbody>
                    </table>

                    <table v-if="tab === 'payments'" class="w-full text-sm">
                        <thead><tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Receipt</th><th class="px-2 py-2 font-medium">Date</th><th class="px-2 py-2 font-medium">Method</th><th class="px-2 py-2 text-end font-medium">Amount</th><th class="px-2 py-2 text-end font-medium">Advance</th><th class="px-2 py-2 font-medium">Status</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="p in data.payments" :key="p.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="Object.assign(payDetail, { show: true, id: p.id })">
                                <td class="px-2 py-2 font-medium text-brand-600">{{ p.receipt_no }}</td>
                                <td class="px-2 py-2">{{ fmtDate(p.payment_date) }}</td>
                                <td class="px-2 py-2">{{ label(p.method) }} <span class="text-xs text-slate-400">{{ p.transaction_id }}</span></td>
                                <td class="px-2 py-2 text-end font-medium">{{ money(p.amount) }}</td>
                                <td class="px-2 py-2 text-end text-emerald-700">{{ Number(p.unallocated) ? money(p.unallocated) : '' }}</td>
                                <td class="px-2 py-2"><StatusBadge :status="p.status" /></td>
                            </tr>
                            <tr v-if="!data.payments.length"><td colspan="6" class="px-2 py-6 text-center text-slate-400">No payments yet</td></tr>
                        </tbody>
                    </table>

                    <div v-if="tab === 'deposits'">
                        <form v-if="can.payment" class="mb-3 grid grid-cols-2 gap-2 rounded-md border border-slate-200 p-2 md:grid-cols-6" @submit.prevent="depositAction('/isp/deposit', { ...depositForm, customer_id: customerId })">
                            <input v-model="depositForm.amount" type="number" min="0" :step="moneyStep()" required placeholder="Deposit amount" class="rounded-md border border-slate-300 px-2 py-1 text-sm" />
                            <select v-model="depositForm.method" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <option v-for="m in PAYMENT_METHODS.filter((x) => ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'card', 'other'].includes(x.value))" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                            <select v-if="depositForm.method !== 'cash'" v-model="depositForm.bank_id" required class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <option :value="null" disabled>Received into…</option>
                                <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <select v-model="depositForm.connection_id" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <option :value="null">Any connection</option>
                                <option v-for="c in data.connections" :key="c.id" :value="c.id">{{ c.code }}</option>
                            </select>
                            <input v-model="depositForm.notes" maxlength="255" placeholder="Note (e.g. router)" class="rounded-md border border-slate-300 px-2 py-1 text-sm" />
                            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white">Receive deposit</button>
                        </form>
                        <table class="w-full text-sm">
                            <thead><tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                                <th class="px-2 py-2 font-medium">Deposit</th><th class="px-2 py-2 font-medium">Date</th><th class="px-2 py-2 font-medium">Connection</th><th class="px-2 py-2 text-end font-medium">Amount</th><th class="px-2 py-2 text-end font-medium">Held</th><th class="px-2 py-2 font-medium">Note</th><th class="px-2 py-2"></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="d in data.deposits" :key="d.id" class="border-b border-slate-100">
                                    <td class="px-2 py-2 font-medium">{{ d.deposit_no }}</td>
                                    <td class="px-2 py-2">{{ fmtDate(d.received_date) }}</td>
                                    <td class="px-2 py-2">{{ d.connection?.code || '—' }}</td>
                                    <td class="px-2 py-2 text-end">{{ money(d.amount) }}</td>
                                    <td class="px-2 py-2 text-end font-medium" :class="d.held > 0 ? 'text-indigo-700' : 'text-slate-400'">{{ money(d.held) }}</td>
                                    <td class="px-2 py-2 text-xs text-slate-500">{{ d.notes }}</td>
                                    <td class="px-2 py-2 text-end">
                                        <template v-if="d.held > 0">
                                            <button v-if="can.payment" type="button" class="me-1 rounded border border-slate-300 px-2 py-0.5 text-xs" @click="applyDeposit(d)">Apply to dues</button>
                                            <button v-if="can.refund" type="button" class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-600" @click="refundDeposit(d)">Refund</button>
                                        </template>
                                    </td>
                                </tr>
                                <tr v-if="!data.deposits.length"><td colspan="7" class="px-2 py-6 text-center text-slate-400">No deposits. A deposit is held for the customer and never pays bills unless applied.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <CompliancePanel v-if="tab === 'compliance'" :customer-id="customerId" @changed="load" />

                    <LedgerStatement v-if="tab === 'ledger'" ref="ledgerRef" :customer="data.customer" />
                </div>
            </div>
        </template>

        <ConnectionPanel :show="connPanel.show" :connection-id="connPanel.id" :can="can" @close="connPanel.show = false" @changed="load" @edit="(c) => { connPanel.show = false; Object.assign(connForm, { show: true, connection: c }); }" />
        <ConnectionFormModal :can-credit="!!can.connectionCredit" :show="connForm.show" :connection="connForm.connection" :customer-id="customerId" @close="connForm.show = false" @saved="load" />
        <InvoiceDetailModal :show="invDetail.show" :invoice-id="invDetail.id" :can="invoiceCan" @close="invDetail.show = false" @changed="load" @edit-draft="(inv) => { invDetail.show = false; Object.assign(manual, { show: true, draft: inv }); }" @receive="openReceive" />
        <ManualInvoiceModal :show="manual.show" :draft="manual.draft" :customer-id="customerId" @close="manual.show = false" @saved="load" />
        <PaymentDetailModal :show="payDetail.show" :payment-id="payDetail.id" :can="paymentCan" @close="payDetail.show = false" @changed="load" />
        <Modal :show="receive.show" max-width="max-w-5xl" @close="receive.show = false">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-base font-semibold text-slate-800">Receive payment — {{ data?.customer?.name }}</h2>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="receive.show = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="p-4">
                <ReceivePaymentForm v-if="receive.show" :customer-id="customerId" :invoice-id="receive.invoiceId" @saved="(id) => { receive.show = false; load(); Object.assign(payDetail, { show: true, id }); }" />
            </div>
        </Modal>
        <TerminalOffcanvas v-if="data" v-model="terminal.show" :connections="data.connections" :connection-id="terminal.id" @changed="load" />
    </div>
</template>
