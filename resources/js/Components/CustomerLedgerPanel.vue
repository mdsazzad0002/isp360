<script setup>
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SaleQuickView from '../Pages/Sale/SaleQuickView.vue';
import SaleReturnQuickView from '../Pages/Sale/SaleReturnQuickView.vue';
import CareFlowOrderQuickView from '../Pages/CareFlow/Order/OrderQuickView.vue';
import ReceiveQuickView from '../Pages/Account/ReceiveQuickView.vue';
import { printDocument } from '../lib/print';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    customerId: { type: [String, Number], default: null },
    customerName: { type: String, default: '' },
    customerCode: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const page = usePage();

const loading = ref(false);
const ledgers = ref([]);
const previousBalance = ref(0);

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
    loadLedger();
}
function isActiveRange(days) {
    return dateFrom.value === daysAgoStr(days) && dateTo.value === todayStr();
}

const dateFrom = ref(daysAgoStr(14));
const dateTo = ref(todayStr());

function close() {
    emit('update:modelValue', false);
}

async function loadLedger() {
    if (!props.customerId) return;
    loading.value = true;
    ledgers.value = [];
    previousBalance.value = 0;
    try {
        const res = await axios.post('/get-customer-ledger', {
            customerId: props.customerId,
            dateFrom: dateFrom.value,
            dateTo: dateTo.value,
        });
        ledgers.value = res.data.ledgers;
        previousBalance.value = res.data.previousBalance;
    } finally {
        loading.value = false;
    }
}

function clearDates() {
    dateFrom.value = daysAgoStr(14);
    dateTo.value = todayStr();
    loadLedger();
}

function sumField(field) {
    return ledgers.value.reduce((pre, cur) => pre + parseFloat(cur[field]), 0).toFixed(2);
}

function lastBalance() {
    return ledgers.value.length ? parseFloat(ledgers.value[ledgers.value.length - 1].balance).toFixed(2) : '0.00';
}

function isClickableLedgerRow(item) {
    return ['a', 'b', 'c', 'd', 'e'].includes(item.sequence);
}

const showQuickView = ref(false);
const quickViewSaleId = ref(null);
const showReturnQuickView = ref(false);
const quickViewReturnId = ref(null);
const showCareflowQuickView = ref(false);
const quickViewCareflowOrderId = ref(null);
const showReceiveQuickView = ref(false);
const quickViewReceiveId = ref(null);
const quickViewReceiveMode = ref('receive');

function openInvoice(item) {
    if (item.sequence === 'a') {
        quickViewSaleId.value = item.id;
        showQuickView.value = true;
    } else if (item.sequence === 'b') {
        quickViewReturnId.value = item.id;
        showReturnQuickView.value = true;
    } else if (item.sequence === 'e') {
        quickViewCareflowOrderId.value = item.id;
        showCareflowQuickView.value = true;
    } else if (item.sequence === 'c' || item.sequence === 'd') {
        quickViewReceiveMode.value = item.sequence === 'c' ? 'payment' : 'receive';
        quickViewReceiveId.value = item.id;
        showReceiveQuickView.value = true;
    }
}

function print() {
    const cell = 'border border-slate-300 px-2 py-1';
    const entityText = `
        <p><strong>Customer ID:</strong> ${props.customerCode}</p>
        <p><strong>Name:</strong> ${props.customerName}</p>`;
    const dateText = `<p><strong>Statement From:</strong> ${dateFrom.value} to ${dateTo.value}</p>`;

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
            <thead><tr><th class="${cell}">Date</th><th class="${cell}">Description</th><th class="${cell}">Bill</th><th class="${cell}">Inv.Paid</th><th class="${cell}">Inv.Due</th><th class="${cell}">Payment</th><th class="${cell}">Receive</th><th class="${cell}">Returned</th><th class="${cell}">Balance</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument('Customer Ledger', bodyHtml, page.props.company);
}

watch(
    () => [props.modelValue, props.customerId],
    ([open, id]) => {
        if (open && id) {
            dateFrom.value = daysAgoStr(14);
            dateTo.value = todayStr();
            loadLedger();
        }
    },
    { immediate: true }
);
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[320px] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-800"><i class="bi bi-journal-text"></i> {{ customerName }}</h2>
                        <p v-if="customerCode" class="text-xs text-slate-500">{{ customerCode }} — Customer Ledger</p>
                    </div>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto bg-slate-100 p-5">
                    <div v-if="loading" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
                    <template v-else>
                        <div class="mb-3 flex flex-wrap items-end gap-3">
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
                            <button type="button" @click="loadLedger" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">Show</button>
                            <button type="button" @click="clearDates" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Clear</button>
                            <button type="button" @click="print" title="Print" class="ml-auto text-slate-500 hover:text-brand-500">
                                <i class="bi bi-printer text-lg"></i>
                            </button>
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                        <th class="px-2 py-2 font-medium">Date</th>
                                        <th class="px-2 py-2 font-medium">Description</th>
                                        <th class="px-2 py-2 font-medium text-right">Bill</th>
                                        <th class="px-2 py-2 font-medium text-right">Inv.Paid</th>
                                        <th class="px-2 py-2 font-medium text-right">Inv.Due</th>
                                        <th class="px-2 py-2 font-medium text-right">Payment</th>
                                        <th class="px-2 py-2 font-medium text-right">Receive</th>
                                        <th class="px-2 py-2 font-medium text-right">Returned</th>
                                        <th class="px-2 py-2 font-medium text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b border-slate-100">
                                        <td class="px-2 py-1.5"></td>
                                        <td colspan="7" class="px-2 py-1.5">Previous Balance</td>
                                        <td class="px-2 py-1.5 text-right">{{ previousBalance }}</td>
                                    </tr>
                                    <tr
                                        v-for="(item, index) in ledgers"
                                        :key="index"
                                        class="border-b border-slate-100"
                                        :class="isClickableLedgerRow(item) ? 'cursor-pointer hover:bg-brand-50/40' : ''"
                                        @click="openInvoice(item)"
                                    >
                                        <td class="px-2 py-1.5 whitespace-nowrap">{{ item.date }}</td>
                                        <td class="px-2 py-1.5">
                                            <span :class="isClickableLedgerRow(item) ? 'text-brand-600 underline decoration-dotted' : ''">{{ item.description }}</span>
                                        </td>
                                        <td class="px-2 py-1.5 text-right">{{ item.bill }}</td>
                                        <td class="px-2 py-1.5 text-right">{{ item.paid }}</td>
                                        <td class="px-2 py-1.5 text-right">{{ item.due }}</td>
                                        <td class="px-2 py-1.5 text-right">{{ item.cash_payment }}</td>
                                        <td class="px-2 py-1.5 text-right">{{ item.cash_receive }}</td>
                                        <td class="px-2 py-1.5 text-right">{{ item.return_amount }}</td>
                                        <td class="px-2 py-1.5 text-right font-medium">{{ parseFloat(item.balance).toFixed(2) }}</td>
                                    </tr>
                                    <tr v-if="ledgers.length === 0">
                                        <td colspan="9" class="px-2 py-6 text-center text-slate-400">No ledger entries found</td>
                                    </tr>
                                    <tr v-if="ledgers.length > 0" class="bg-slate-50 font-semibold">
                                        <td colspan="2" class="px-2 py-2 text-center">Total</td>
                                        <td class="px-2 py-2 text-right">{{ sumField('bill') }}</td>
                                        <td class="px-2 py-2 text-right">{{ sumField('paid') }}</td>
                                        <td class="px-2 py-2 text-right">{{ sumField('due') }}</td>
                                        <td class="px-2 py-2 text-right">{{ sumField('cash_payment') }}</td>
                                        <td class="px-2 py-2 text-right">{{ sumField('cash_receive') }}</td>
                                        <td class="px-2 py-2 text-right">{{ sumField('return_amount') }}</td>
                                        <td class="px-2 py-2 text-right">{{ lastBalance() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>

                <div class="border-t border-slate-200 bg-white p-3">
                    <button type="button" @click="close" class="w-full rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                </div>
            </div>
        </div>

        <SaleQuickView v-model="showQuickView" :sale-id="quickViewSaleId" />
        <SaleReturnQuickView v-model="showReturnQuickView" :return-id="quickViewReturnId" />
        <CareFlowOrderQuickView v-model="showCareflowQuickView" :order-id="quickViewCareflowOrderId" />
        <ReceiveQuickView v-model="showReceiveQuickView" :record-id="quickViewReceiveId" :mode="quickViewReceiveMode" />
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
