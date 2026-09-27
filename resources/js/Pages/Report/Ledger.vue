<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SearchSelect from '../../Components/SearchSelect.vue';
import ReceiveQuickView from '../Account/ReceiveQuickView.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaymentOffcanvas from '../../Components/PaymentOffcanvas.vue';
import { useToast } from '../../lib/toast';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const props = defineProps({
    mode: { type: String, required: true }, // 'customer' | 'supplier' | 'employee'
    preselectId: { type: [String, Number], default: null },
});

const modeLabels = { customer: 'Customer', supplier: 'Supplier', employee: 'Employee' };
const modeLabel = modeLabels[props.mode] ?? 'Supplier';

const defaultColumnLabels = { bill: 'Bill', paid: 'Inv.Paid', due: 'Inv.Due', cash_payment: 'Payment', cash_receive: 'Receive', return_amount: 'Returned', balance: 'Balance' };
const employeeColumnLabels = { bill: 'Salary', paid: 'Paid', due: 'Due', cash_payment: 'Payment', cash_receive: 'Advance', return_amount: 'Returned', balance: 'Payable' };
const columnLabels = props.mode === 'employee' ? employeeColumnLabels : defaultColumnLabels;

function balanceClass(value) {
    if (props.mode !== 'employee') return '';
    return parseFloat(value) >= 0 ? 'text-emerald-600' : 'text-red-600';
}

const page = usePage();
const toast = useToast();
const title = `${modeLabel} Ledger`;
const listUrl = `/get-${props.mode}`;
const ledgerUrl = `/get-${props.mode}-ledger`;

function formatDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function todayStr() {
    return formatDate(new Date());
}
function daysAgoStr(days) {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return formatDate(d);
}

const RANGE_SHORTCUTS = [
    { label: '15D', days: 14 },
    { label: '1M', days: 29 },
    { label: '2M', days: 59 },
];
function applyRange(days) {
    dateFrom.value = daysAgoStr(days);
    dateTo.value = todayStr();
    if (selected.value) showLedger();
}
function isActiveRange(days) {
    return dateFrom.value === daysAgoStr(days) && dateTo.value === todayStr();
}

// Default to the last 15 days rather than just today — a single day's
// window on first load showed almost nothing for most customers.
const dateFrom = ref(daysAgoStr(14));
const dateTo = ref(todayStr());
const ledgers = ref([]);
const previousBalance = ref(0);
const options = ref([]);
const selected = ref(null);
const isLoading = ref(null);

function getOptions() {
    axios.post(listUrl).then((res) => {
        options.value = res.data;
        if (props.preselectId) {
            const match = options.value.find((item) => item.id == props.preselectId);
            if (match) {
                selected.value = match;
                showLedger();
            }
        }
    });
}

function showLedger() {
    if (selected.value == null) {
        toast.error(`Please select a ${props.mode}`);
        return;
    }
    isLoading.value = false;
    axios
        .post(ledgerUrl, {
            [`${props.mode}Id`]: selected.value.id,
            dateFrom: dateFrom.value,
            dateTo: dateTo.value,
        })
        .then((res) => {
            ledgers.value = res.data.ledgers;
            previousBalance.value = res.data.previousBalance;
            isLoading.value = true;
        });
}

function sumField(field) {
    return ledgers.value.reduce((pre, cur) => pre + parseFloat(cur[field]), 0).toFixed(2);
}

function lastBalance() {
    return ledgers.value.length ? parseFloat(ledgers.value[ledgers.value.length - 1].balance).toFixed(2) : '0.00';
}

function print() {
    let entityText = '';
    if (selected.value) {
        entityText = `
            <p><strong>${modeLabel} ID:</strong> ${selected.value.code}</p>
            <p><strong>Name:</strong> ${selected.value.name}</p>
            <p><strong>Mobile:</strong> ${selected.value.phone}</p>`;
    }
    const dateText = `<p><strong>Statement From:</strong> ${dateFrom.value} to ${dateTo.value}</p>`;

    const cell = 'border border-slate-300 px-2 py-1';
    const rowsHtml =
        `<tr><td class="${cell}"></td><td class="${cell}" colspan="7">Previous Balance</td><td class="${cell}" style="text-align:right;">${previousBalance.value}</td></tr>` +
        ledgers.value
            .map(
                (item) => `
            <tr>
                <td class="${cell}">${item.date}</td>
                <td class="${cell}">${item.description ?? ''}</td>
                <td class="${cell}" style="text-align:right;">${item.bill}</td>
                <td class="${cell}" style="text-align:right;">${item.paid}</td>
                <td class="${cell}" style="text-align:right;">${item.due}</td>
                <td class="${cell}" style="text-align:right;">${item.cash_payment}</td>
                <td class="${cell}" style="text-align:right;">${item.cash_receive}</td>
                <td class="${cell}" style="text-align:right;">${item.return_amount}</td>
                <td class="${cell}" style="text-align:right;">${parseFloat(item.balance).toFixed(2)}</td>
            </tr>`
            )
            .join('') +
        (ledgers.value.length
            ? `<tr><th class="${cell}" colspan="2" style="text-align:center;">Total</th><th class="${cell}" style="text-align:right;">${sumField('bill')}</th><th class="${cell}" style="text-align:right;">${sumField('paid')}</th><th class="${cell}" style="text-align:right;">${sumField('due')}</th><th class="${cell}" style="text-align:right;">${sumField('cash_payment')}</th><th class="${cell}" style="text-align:right;">${sumField('cash_receive')}</th><th class="${cell}" style="text-align:right;">${sumField('return_amount')}</th><th class="${cell}" style="text-align:right;">${lastBalance()}</th></tr>`
            : '');

    const bodyHtml = `
        ${entityText}
        ${dateText}
        <table class="w-full border border-collapse border-slate-300 text-sm" style="border-collapse:collapse;">
            <thead><tr><th class="border border-slate-300 px-2 py-1">Date</th><th class="border border-slate-300 px-2 py-1">Description</th><th class="border border-slate-300 px-2 py-1">${columnLabels.bill}</th><th class="border border-slate-300 px-2 py-1">${columnLabels.paid}</th><th class="border border-slate-300 px-2 py-1">${columnLabels.due}</th><th class="border border-slate-300 px-2 py-1">${columnLabels.cash_payment}</th><th class="border border-slate-300 px-2 py-1">${columnLabels.cash_receive}</th><th class="border border-slate-300 px-2 py-1">${columnLabels.return_amount}</th><th class="border border-slate-300 px-2 py-1">${columnLabels.balance}</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument(title, bodyHtml, page.props.company);
}

// Positive balance means the customer owes us (Collect) but the supplier is owed by us
// (Pay); negative flips both — same sign convention PaymentOffcanvas uses internally.
const showPaymentAction = props.mode === 'customer' || props.mode === 'supplier';

function paymentActionLabel() {
    const positive = parseFloat(lastBalance()) >= 0;
    if (props.mode === 'customer') return positive ? 'Received' : 'Pay';
    return positive ? 'Pay' : 'Received';
}
function paymentActionClass() {
    return paymentActionLabel() === 'Pay' ? 'bg-red-500 hover:bg-red-600' : 'bg-emerald-600 hover:bg-emerald-700';
}

const showPaymentOffcanvas = ref(false);
function openPayment() {
    showPaymentOffcanvas.value = true;
}
function onPaid() {
    showLedger();
}

const showReceiveQuickView = ref(false);
const quickViewRecordId = ref(null);
const quickViewRecordMode = ref('receive');

function isClickableLedgerRow(item) {
    return ['c', 'd'].includes(item.sequence);
}

function openInvoice(item) {
    if (item.sequence === 'c') {
        quickViewRecordId.value = item.id;
        quickViewRecordMode.value = 'payment';
        showReceiveQuickView.value = true;
    } else if (item.sequence === 'd') {
        quickViewRecordId.value = item.id;
        quickViewRecordMode.value = 'receive';
        showReceiveQuickView.value = true;
    }
}

onMounted(getOptions);
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <form @submit.prevent="showLedger" class="flex flex-wrap items-end gap-3">
                <div class="w-64">
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ modeLabel }}</label>
                    <SearchSelect :options="options" v-model="selected" label="display_name" :placeholder="`Select ${mode}`" />
                </div>
                <div>
                    <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
                        From
                        <button
                            v-for="shortcut in RANGE_SHORTCUTS"
                            :key="shortcut.label"
                            type="button"
                            @click="applyRange(shortcut.days)"
                            :class="
                                isActiveRange(shortcut.days)
                                    ? 'border-brand-500 bg-brand-500 text-white'
                                    : 'border-slate-300 text-slate-500 hover:border-brand-400 hover:text-brand-600'
                            "
                            class="rounded border px-1.5 py-0.5 text-[10px] font-medium"
                        >
                            {{ shortcut.label }}
                        </button>
                    </label>
                    <input type="date" v-model="dateFrom" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <input type="date" v-model="dateTo" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <button type="submit" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">Show</button>
            </form>
        </div>

        <div v-if="isLoading" class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 text-end">
                <button type="button" @click="print" title="Print" class="text-slate-500 hover:text-brand-500">
                    <i class="bi bi-printer text-lg"></i>
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border border-collapse border-slate-200 text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-start text-slate-600">
                            <th class="border border-slate-200 px-2 py-2 font-medium">Date</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">Description</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.bill }}</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.paid }}</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.due }}</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.cash_payment }}</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.cash_receive }}</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.return_amount }}</th>
                            <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.balance }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="border border-slate-200 px-2 py-1.5"></td>
                            <td colspan="7" class="border border-slate-200 px-2 py-1.5">Previous Balance</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ previousBalance }}</td>
                        </tr>
                        <tr
                            v-for="(item, index) in ledgers"
                            :key="index"
                            :class="isClickableLedgerRow(item) ? 'cursor-pointer hover:bg-brand-50/40' : ''"
                            @click="openInvoice(item)"
                        >
                            <td class="border border-slate-200 px-2 py-1.5">{{ item.date }}</td>
                            <td class="border border-slate-200 px-2 py-1.5">
                                <span :class="isClickableLedgerRow(item) ? 'text-brand-600 underline decoration-dotted' : ''">{{ item.description }}</span>
                            </td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.bill }}</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.paid }}</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.due }}</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.cash_payment }}</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.cash_receive }}</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.return_amount }}</td>
                            <td class="border border-slate-200 px-2 py-1.5 text-end font-medium" :class="balanceClass(item.balance)">{{ parseFloat(item.balance).toFixed(2) }}</td>
                        </tr>
                        <tr v-if="ledgers.length > 0" class="bg-slate-50 font-semibold">
                            <td colspan="2" class="border border-slate-200 px-2 py-2 text-center">Total</td>
                            <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('bill') }}</td>
                            <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('paid') }}</td>
                            <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('due') }}</td>
                            <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('cash_payment') }}</td>
                            <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('cash_receive') }}</td>
                            <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('return_amount') }}</td>
                            <td class="border border-slate-200 px-2 py-2 text-end" :class="balanceClass(lastBalance())">
                                <div class="flex items-center justify-end gap-2">
                                    <span>{{ lastBalance() }}</span>
                                    <button
                                        v-if="showPaymentAction && selected && parseFloat(lastBalance()) !== 0"
                                        type="button"
                                        @click="openPayment"
                                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium text-white"
                                        :class="paymentActionClass()"
                                    >
                                        <i :class="paymentActionLabel() === 'Pay' ? 'bi bi-cash-stack' : 'bi bi-cash-coin'"></i>
                                        {{ paymentActionLabel() }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ReceiveQuickView v-model="showReceiveQuickView" :record-id="quickViewRecordId" :mode="quickViewRecordMode" />

        <PaymentOffcanvas v-if="showPaymentAction" v-model="showPaymentOffcanvas" :mode="mode" :entity="selected ?? {}" @paid="onPaid" />
    </div>
</template>
