<script setup>
import { moneyDecimals, today } from '../../../lib/isp';
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ReceiveQuickView from '../../Account/ReceiveQuickView.vue';
import PaymentOffcanvas from '../../../Components/PaymentOffcanvas.vue';
import { printDocument } from '../../../lib/print';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    customer: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const page = usePage();

function close() {
    emit('update:modelValue', false);
}

function formatDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function todayStr() {
    return formatDate(new Date(today() + 'T00:00:00'));
}
function daysAgoStr(days) {
    const d = new Date(today() + 'T00:00:00');
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
    showLedger();
}
function isActiveRange(days) {
    return dateFrom.value === daysAgoStr(days) && dateTo.value === todayStr();
}

const dateFrom = ref(daysAgoStr(14));
const dateTo = ref(todayStr());
const ledgers = ref([]);
const previousBalance = ref(0);
const isLoading = ref(false);

function showLedger() {
    if (!props.customer?.id) return;
    isLoading.value = false;
    axios
        .post('/get-customer-ledger', {
            customerId: props.customer.id,
            dateFrom: dateFrom.value,
            dateTo: dateTo.value,
        })
        .then((res) => {
            ledgers.value = res.data.ledgers;
            previousBalance.value = res.data.previousBalance;
            isLoading.value = true;
        });
}

watch(
    () => [props.modelValue, props.customer?.id],
    ([open, id]) => {
        if (open && id) {
            dateFrom.value = daysAgoStr(14);
            dateTo.value = todayStr();
            showLedger();
        }
    },
    { immediate: true }
);

function sumField(field) {
    return ledgers.value.reduce((pre, cur) => pre + parseFloat(cur[field]), 0).toFixed(moneyDecimals());
}
function lastBalance() {
    return ledgers.value.length ? parseFloat(ledgers.value[ledgers.value.length - 1].balance).toFixed(moneyDecimals()) : (0).toFixed(moneyDecimals());
}

function print() {
    const entityText = `
        <p><strong>Customer ID:</strong> ${props.customer.code ?? ''}</p>
        <p><strong>Name:</strong> ${props.customer.name ?? ''}</p>
        <p><strong>Mobile:</strong> ${props.customer.phone ?? ''}</p>`;
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
                <td class="${cell}" style="text-align:right;">${parseFloat(item.balance).toFixed(moneyDecimals())}</td>
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
            <thead><tr><th class="border border-slate-300 px-2 py-1">Date</th><th class="border border-slate-300 px-2 py-1">Description</th><th class="border border-slate-300 px-2 py-1">Bill</th><th class="border border-slate-300 px-2 py-1">Inv.Paid</th><th class="border border-slate-300 px-2 py-1">Inv.Due</th><th class="border border-slate-300 px-2 py-1">Payment</th><th class="border border-slate-300 px-2 py-1">Receive</th><th class="border border-slate-300 px-2 py-1">Returned</th><th class="border border-slate-300 px-2 py-1">Balance</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument('Customer Ledger', bodyHtml, page.props.company);
}

const showPaymentOffcanvas = ref(false);
function openPayment() {
    showPaymentOffcanvas.value = true;
}
function onPaid() {
    showLedger();
}
function paymentActionLabel() {
    return parseFloat(lastBalance()) >= 0 ? 'Received' : 'Pay';
}
function paymentActionClass() {
    return paymentActionLabel() === 'Pay' ? 'bg-red-500 hover:bg-red-600' : 'bg-emerald-600 hover:bg-emerald-700';
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
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[320px] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-journal-text"></i> Customer Ledger — {{ customer?.name }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <form @submit.prevent="showLedger" class="flex flex-wrap items-end gap-3">
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
                            <button type="button" @click="print" title="Print" class="ms-auto text-slate-500 hover:text-brand-500">
                                <i class="bi bi-printer text-lg"></i>
                            </button>
                        </form>
                    </div>

                    <div v-if="isLoading" class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="w-full border border-collapse border-slate-200 text-sm">
                                <thead>
                                    <tr class="bg-slate-50 text-start text-slate-600">
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Date</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Description</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Bill</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Inv.Paid</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Inv.Due</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Payment</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Receive</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Returned</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Balance</th>
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
                                        <td class="border border-slate-200 px-2 py-1.5 text-end font-medium">{{ parseFloat(item.balance).toFixed(moneyDecimals()) }}</td>
                                    </tr>
                                    <tr v-if="ledgers.length > 0" class="bg-slate-50 font-semibold">
                                        <td colspan="2" class="border border-slate-200 px-2 py-2 text-center">Total</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('bill') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('paid') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('due') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('cash_payment') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('cash_receive') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('return_amount') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-end">
                                            <div class="flex items-center justify-end gap-2">
                                                <span>{{ lastBalance() }}</span>
                                                <button
                                                    v-if="customer?.id && parseFloat(lastBalance()) !== 0"
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
                </div>
            </div>
        </div>

        <ReceiveQuickView v-model="showReceiveQuickView" :record-id="quickViewRecordId" :mode="quickViewRecordMode" />
        <PaymentOffcanvas v-model="showPaymentOffcanvas" mode="customer" :entity="customer ?? {}" @paid="onPaid" />
    </Teleport>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
.animate-slide-in {
    animation: slide-in 0.25s ease-out;
}
</style>
