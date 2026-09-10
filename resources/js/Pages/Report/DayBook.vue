<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import DayBookDetailOffcanvas from './DayBookDetailOffcanvas.vue';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const page = usePage();

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function currency(value) {
    return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const dateFrom = ref(todayStr());
const dateTo = ref(todayStr());
const isLoading = ref(false);
const data = ref(null);
const showHelp = ref(false);

const showDetailPanel = ref(false);
const detailMode = ref('cash');
const detailBankId = ref('');
const detailBankName = ref('');
const detailSection = ref('');

const SECTION_LABELS = {
    opening: 'Opening Balance',
    receipt: 'Receipt',
    payment: 'Payment',
    closing: 'Closing Balance',
};
const detailSectionLabel = ref('');

function openCashDetail(section) {
    detailMode.value = 'cash';
    detailBankId.value = '';
    detailBankName.value = '';
    detailSection.value = section;
    detailSectionLabel.value = SECTION_LABELS[section];
    showDetailPanel.value = true;
}

function openBankDetail(bank, section) {
    detailMode.value = 'bank';
    detailBankId.value = bank.id;
    detailBankName.value = bank.name;
    detailSection.value = section;
    detailSectionLabel.value = SECTION_LABELS[section];
    showDetailPanel.value = true;
}

const balanceHelp = [
    { title: 'Opening Balance', desc: 'নির্বাচিত তারিখের আগের দিন পর্যন্ত ক্যাশ ইন হ্যান্ড ও সব ব্যাংক অ্যাকাউন্টের মোট জমা ব্যালেন্স।' },
    { title: 'Receipt', desc: 'নির্বাচিত তারিখ পরিসরে যে পরিমাণ ক্যাশ বা ব্যাংক ব্যালেন্স বেড়েছে (আয়/জমা)।' },
    { title: 'Payment', desc: 'নির্বাচিত তারিখ পরিসরে যে পরিমাণ ক্যাশ বা ব্যাংক ব্যালেন্স কমেছে (খরচ/উত্তোলন)।' },
    { title: 'Closing Balance', desc: 'নির্বাচিত তারিখ পর্যন্ত ক্যাশ ইন হ্যান্ড ও সব ব্যাংক অ্যাকাউন্টের মোট জমা ব্যালেন্স (Opening + Receipt − Payment)।' },
    { title: 'Total (বাম পাশে)', desc: 'Opening Balance + Receipt এর যোগফল।' },
    { title: 'Total (ডান পাশে)', desc: 'Payment + Closing Balance এর যোগফল। এটি বাম পাশের Total এর সমান হওয়া উচিত।' },
];

async function showDayBook() {
    isLoading.value = true;
    try {
        const res = await axios.post('/get-dayBook', { dateFrom: dateFrom.value, dateTo: dateTo.value });
        data.value = res.data;
    } finally {
        isLoading.value = false;
    }
}

function print() {
    if (!data.value) return;
    const d = data.value;

    const leftRows = `
        <tr><th colspan="2">Opening Balance</th></tr>
        <tr><td style="padding-left:16px;"><strong>Cash in Hand</strong></td><td style="text-align:right;"><strong>${currency(d.openingCash)}</strong></td></tr>
        <tr><td style="padding-left:16px;"><strong>Bank Accounts</strong></td><td style="text-align:right;"><strong>${currency(d.totalOpeningBank)}</strong></td></tr>
        ${d.openingBanks.map((b) => `<tr><td style="padding-left:32px;">${b.name}</td><td style="text-align:right;">${currency(b.amount)}</td></tr>`).join('')}
        <tr><th colspan="2">Receipt</th></tr>
        ${d.receiptCash > 0 ? `<tr><td style="padding-left:16px;">Cash</td><td style="text-align:right;">${currency(d.receiptCash)}</td></tr>` : ''}
        ${d.receiptBanks.map((b) => `<tr><td style="padding-left:16px;">${b.name}</td><td style="text-align:right;">${currency(b.amount)}</td></tr>`).join('')}
        <tr><th>Total</th><th style="text-align:right;">${currency(d.leftTotal)}</th></tr>`;

    const rightRows = `
        <tr><th colspan="2">Payment</th></tr>
        ${d.paymentCash > 0 ? `<tr><td style="padding-left:16px;">Cash</td><td style="text-align:right;">${currency(d.paymentCash)}</td></tr>` : ''}
        ${d.paymentBanks.map((b) => `<tr><td style="padding-left:16px;">${b.name}</td><td style="text-align:right;">${currency(b.amount)}</td></tr>`).join('')}
        <tr><th colspan="2">Closing Balance</th></tr>
        <tr><td style="padding-left:16px;"><strong>Bank Accounts</strong></td><td style="text-align:right;"><strong>${currency(d.totalClosingBank)}</strong></td></tr>
        ${d.closingBanks.map((b) => `<tr><td style="padding-left:32px;">${b.name}</td><td style="text-align:right;">${currency(b.amount)}</td></tr>`).join('')}
        <tr><td style="padding-left:16px;"><strong>Cash in Hand</strong></td><td style="text-align:right;"><strong>${currency(d.closingCash)}</strong></td></tr>
        <tr><th>Total</th><th style="text-align:right;">${currency(d.rightTotal)}</th></tr>`;

    const bodyHtml = `
        <table style="width:100%;">
            <tr><td style="width:50%;vertical-align:top;"><table style="width:100%;"><thead><tr><th>Description</th><th style="text-align:right;">Amount</th></tr></thead><tbody>${leftRows}</tbody></table></td>
            <td style="width:50%;vertical-align:top;"><table style="width:100%;"><thead><tr><th>Description</th><th style="text-align:right;">Amount</th></tr></thead><tbody>${rightRows}</tbody></table></td></tr>
        </table>`;

    printDocument('Day Book', bodyHtml, page.props.company);
}

onMounted(showDayBook);
</script>

<template>
    <div class="mx-auto space-y-3 p-4">
        <div class="mb-1 flex items-center gap-2">
            <i class="bi bi-journal-check text-xl text-brand-500"></i>
            <h1 class="text-lg font-semibold text-slate-800">Day Book</h1>
            <button
                type="button"
                @click="showHelp = true"
                class="ml-1 flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50"
                title="ব্যালেন্স গুলোর অর্থ জানুন"
            >
                <i class="bi bi-info-circle"></i> Balance বুঝুন
            </button>
        </div>

        <div v-if="showHelp" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showHelp = false">
            <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-lg">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-800">Balance এর অর্থ</h2>
                    <button type="button" @click="showHelp = false" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <ul class="space-y-2 text-sm">
                    <li v-for="item in balanceHelp" :key="item.title">
                        <span class="font-semibold text-slate-700">{{ item.title }}:</span>
                        <span class="text-slate-600"> {{ item.desc }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <form @submit.prevent="showDayBook" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Date From</label>
                    <input type="date" v-model="dateFrom" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Date To</label>
                    <input type="date" v-model="dateTo" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <button type="submit" :disabled="isLoading" class="flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    <i class="bi" :class="isLoading ? 'bi-arrow-repeat animate-spin' : 'bi-search'"></i> Search
                </button>
                <button v-if="data" type="button" @click="print" class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-500">
                    <i class="bi bi-printer"></i> Print
                </button>
            </form>
        </div>

        <div v-if="data" class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <!-- Left: Opening + Receipt -->
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100 bg-slate-50/60">
                            <td colspan="2" class="px-3 py-1.5 font-semibold text-slate-700">Opening Balance</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3 font-semibold">
                                <button type="button" @click="openCashDetail('opening')" class="text-brand-600 hover:underline">Cash in Hand</button>
                            </td>
                            <td class="px-3 py-1.5 text-right font-semibold">{{ currency(data.openingCash) }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3 font-semibold">Bank Accounts</td>
                            <td class="px-3 py-1.5 text-right font-semibold">{{ currency(data.totalOpeningBank) }}</td>
                        </tr>
                        <tr v-for="(b, i) in data.openingBanks" :key="'ob' + i" class="border-b border-slate-100 text-slate-500">
                            <td class="py-1.5 pl-10 pr-3">
                                <button type="button" @click="openBankDetail(b, 'opening')" class="text-brand-600 hover:underline">{{ b.name }}</button>
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ currency(b.amount) }}</td>
                        </tr>

                        <tr class="border-b border-slate-100 bg-slate-50/60">
                            <td colspan="2" class="px-3 py-1.5 font-semibold text-emerald-700">Receipt</td>
                        </tr>
                        <tr v-if="data.receiptCash > 0" class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3">
                                <button type="button" @click="openCashDetail('receipt')" class="text-brand-600 hover:underline">Cash</button>
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ currency(data.receiptCash) }}</td>
                        </tr>
                        <tr v-for="(b, i) in data.receiptBanks" :key="'rb' + i" class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3">
                                <button type="button" @click="openBankDetail(b, 'receipt')" class="text-brand-600 hover:underline">{{ b.name }}</button>
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ currency(b.amount) }}</td>
                        </tr>
                        <tr v-if="data.receiptCash === 0 && data.receiptBanks.length === 0">
                            <td colspan="2" class="px-3 py-3 text-center text-slate-400">No receipts</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-800">
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">{{ currency(data.leftTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Right: Payment + Closing -->
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100 bg-slate-50/60">
                            <td colspan="2" class="px-3 py-1.5 font-semibold text-red-700">Payment</td>
                        </tr>
                        <tr v-if="data.paymentCash > 0" class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3">
                                <button type="button" @click="openCashDetail('payment')" class="text-brand-600 hover:underline">Cash</button>
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ currency(data.paymentCash) }}</td>
                        </tr>
                        <tr v-for="(b, i) in data.paymentBanks" :key="'pb' + i" class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3">
                                <button type="button" @click="openBankDetail(b, 'payment')" class="text-brand-600 hover:underline">{{ b.name }}</button>
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ currency(b.amount) }}</td>
                        </tr>
                        <tr v-if="data.paymentCash === 0 && data.paymentBanks.length === 0">
                            <td colspan="2" class="px-3 py-3 text-center text-slate-400">No payments</td>
                        </tr>

                        <tr class="border-b border-slate-100 bg-slate-50/60">
                            <td colspan="2" class="px-3 py-1.5 font-semibold text-slate-700">Closing Balance</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3 font-semibold">Bank Accounts</td>
                            <td class="px-3 py-1.5 text-right font-semibold">{{ currency(data.totalClosingBank) }}</td>
                        </tr>
                        <tr v-for="(b, i) in data.closingBanks" :key="'cb' + i" class="border-b border-slate-100 text-slate-500">
                            <td class="py-1.5 pl-10 pr-3">
                                <button type="button" @click="openBankDetail(b, 'closing')" class="text-brand-600 hover:underline">{{ b.name }}</button>
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ currency(b.amount) }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-1.5 pl-6 pr-3 font-semibold">
                                <button type="button" @click="openCashDetail('closing')" class="text-brand-600 hover:underline">Cash in Hand</button>
                            </td>
                            <td class="px-3 py-1.5 text-right font-semibold">{{ currency(data.closingCash) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-800">
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">{{ currency(data.rightTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <DayBookDetailOffcanvas
            v-model="showDetailPanel"
            :mode="detailMode"
            :bank-id="detailBankId"
            :bank-name="detailBankName"
            :section="detailSection"
            :section-label="detailSectionLabel"
            :date-from="dateFrom"
            :date-to="dateTo"
        />
    </div>
</template>
