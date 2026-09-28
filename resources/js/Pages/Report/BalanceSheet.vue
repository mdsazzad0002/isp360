<script setup>
import { today, money } from '../../lib/isp';
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import BalanceSheetDetailOffcanvas from './BalanceSheetDetailOffcanvas.vue';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const page = usePage();

function todayStr() {
    const d = new Date(today() + 'T00:00:00');
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function currency(value) {
    return money(value);
}

const date = ref(todayStr());
const isLoading = ref(false);
const data = ref(null);
const showHelp = ref(false);

const helpItems = [
    { title: 'Accounts Receivable', desc: 'গ্রাহকদের কাছে বকেয়া থাকা মোট টাকা (Customer Due, যেখানে due পজিটিভ)।' },
    { title: 'Customer Advance', desc: 'গ্রাহকদের কাছ থেকে অগ্রিম নেওয়া টাকা (Customer Due, যেখানে due নেগেটিভ)।' },
    {
        title: 'Retained Earnings',
        desc: 'এই সিস্টেমে সম্পূর্ণ জেনারেল লেজার (আয়-ব্যয়ের প্রতিটি হিসাব) রাখা হয় না, তাই এই মানটি ব্যালেন্স মেলানোর জন্য গণনা করা হয়েছে: Total Assets − Total Liabilities। এটি প্রকৃত নিট মুনাফার সঠিক প্রতিনিধিত্ব নাও হতে পারে।',
    },
];

const showDetailPanel = ref(false);
const detailSource = ref('');
const detailBankId = ref('');
const detailTitle = ref('');

function openDetail(source, title, bankId = '') {
    detailSource.value = source;
    detailTitle.value = title;
    detailBankId.value = bankId;
    showDetailPanel.value = true;
}

async function showBalanceSheet() {
    isLoading.value = true;
    try {
        const res = await axios.post('/get-balanceSheet', { date: date.value });
        data.value = res.data;
    } finally {
        isLoading.value = false;
    }
}

function print() {
    if (!data.value) return;
    const d = data.value;

    const assetRows = `
        <tr><td>Cash in Hand</td><td style="text-align:right;">${currency(d.assets.cashInHand)}</td></tr>
        <tr><td><strong>Bank Accounts</strong></td><td style="text-align:right;"><strong>${currency(d.assets.totalBank)}</strong></td></tr>
        ${d.assets.bankBalances.map((b) => `<tr><td style="padding-left:16px;">${b.name}</td><td style="text-align:right;">${currency(b.amount)}</td></tr>`).join('')}
        <tr><td>Accounts Receivable (Customer Due)</td><td style="text-align:right;">${currency(d.assets.accountsReceivable)}</td></tr>
        <tr><th>Total Assets</th><th style="text-align:right;">${currency(d.totalAssets)}</th></tr>`;

    const liabilityEquityRows = `
        <tr><th colspan="2">Liabilities</th></tr>
        <tr><td>Customer Advance</td><td style="text-align:right;">${currency(d.liabilities.customerAdvance)}</td></tr>
        <tr><th>Total Liabilities</th><th style="text-align:right;">${currency(d.totalLiabilities)}</th></tr>
        <tr><th colspan="2">Equity</th></tr>
        <tr><td>Retained Earnings (Balancing Figure)</td><td style="text-align:right;">${currency(d.equity.retainedEarnings)}</td></tr>
        <tr><th>Total Equity</th><th style="text-align:right;">${currency(d.totalEquity)}</th></tr>
        <tr><th>Total Liabilities + Equity</th><th style="text-align:right;">${currency(d.totalLiabilitiesAndEquity)}</th></tr>`;

    const bodyHtml = `
        <p><strong>As of:</strong> ${d.date}</p>
        <table style="width:100%;">
            <tr>
                <td style="width:50%;vertical-align:top;"><table style="width:100%;"><thead><tr><th colspan="2">Assets</th></tr></thead><tbody>${assetRows}</tbody></table></td>
                <td style="width:50%;vertical-align:top;"><table style="width:100%;"><tbody>${liabilityEquityRows}</tbody></table></td>
            </tr>
        </table>`;

    printDocument('Balance Sheet', bodyHtml, page.props.company);
}

showBalanceSheet();
</script>

<template>
    <div class="mx-auto space-y-3 p-4">
        <div class="mb-1 flex items-center gap-2">
            <i class="bi bi-clipboard-data text-xl text-brand-500"></i>
            <h1 class="text-lg font-semibold text-slate-800">Balance Sheet</h1>
            <button
                type="button"
                @click="showHelp = true"
                class="ms-1 flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50"
                title="লাইন আইটেমগুলোর অর্থ জানুন"
            >
                <i class="bi bi-info-circle"></i> লাইন আইটেম বুঝুন
            </button>
        </div>

        <div v-if="showHelp" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showHelp = false">
            <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-lg">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-800">লাইন আইটেমের অর্থ</h2>
                    <button type="button" @click="showHelp = false" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <ul class="space-y-2 text-sm">
                    <li v-for="item in helpItems" :key="item.title">
                        <span class="font-semibold text-slate-700">{{ item.title }}:</span>
                        <span class="text-slate-600"> {{ item.desc }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <form @submit.prevent="showBalanceSheet" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">As of Date</label>
                    <input type="date" v-model="date" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <button type="submit" :disabled="isLoading" class="flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    <i class="bi" :class="isLoading ? 'bi-arrow-repeat animate-spin' : 'bi-search'"></i> Show
                </button>
                <button v-if="data" type="button" @click="print" class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-500">
                    <i class="bi bi-printer"></i> Print
                </button>
            </form>
        </div>

        <div v-if="data" class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <!-- Assets -->
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th colspan="2" class="px-3 py-2 font-semibold text-emerald-700">Assets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-1.5">
                                <button type="button" @click="openDetail('cash', 'Cash in Hand')" class="text-brand-600 hover:underline">Cash in Hand</button>
                            </td>
                            <td class="px-3 py-1.5 text-end">{{ currency(data.assets.cashInHand) }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-1.5 font-semibold">Bank Accounts</td>
                            <td class="px-3 py-1.5 text-end font-semibold">{{ currency(data.assets.totalBank) }}</td>
                        </tr>
                        <tr v-for="(b, i) in data.assets.bankBalances" :key="'ba' + i" class="border-b border-slate-100 text-slate-500">
                            <td class="py-1.5 ps-10 pe-3">
                                <button type="button" @click="openDetail('bank', b.name, b.id)" class="text-brand-600 hover:underline">{{ b.name }}</button>
                            </td>
                            <td class="px-3 py-1.5 text-end">{{ currency(b.amount) }}</td>
                        </tr>
                        <tr v-if="data.assets.bankBalances.length === 0" class="border-b border-slate-100 text-slate-400">
                            <td class="py-1.5 ps-10 pe-3">No bank accounts</td>
                            <td class="px-3 py-1.5 text-end">0.00</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-1.5">
                                <button type="button" @click="openDetail('receivable', 'Accounts Receivable (Customer Due)')" class="text-brand-600 hover:underline">Accounts Receivable (Customer Due)</button>
                            </td>
                            <td class="px-3 py-1.5 text-end">{{ currency(data.assets.accountsReceivable) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-800">
                            <td class="px-3 py-2">Total Assets</td>
                            <td class="px-3 py-2 text-end">{{ currency(data.totalAssets) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Liabilities + Equity -->
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th colspan="2" class="px-3 py-2 font-semibold text-red-700">Liabilities</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-1.5">
                                <button type="button" @click="openDetail('customerAdvance', 'Customer Advance')" class="text-brand-600 hover:underline">Customer Advance</button>
                            </td>
                            <td class="px-3 py-1.5 text-end">{{ currency(data.liabilities.customerAdvance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-800">
                            <td class="px-3 py-2">Total Liabilities</td>
                            <td class="px-3 py-2 text-end">{{ currency(data.totalLiabilities) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th colspan="2" class="px-3 py-2 font-semibold text-brand-700">Equity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-1.5">
                                <button type="button" @click="openDetail('retainedEarnings', 'Retained Earnings')" class="text-brand-600 hover:underline">Retained Earnings</button>
                                <span class="text-xs text-slate-400">(balancing figure)</span>
                                <div class="text-xs text-slate-400">
                                    {{ currency(data.totalAssets) }} (Assets) − {{ currency(data.totalLiabilities) }} (Liabilities) = {{ currency(data.equity.retainedEarnings) }}
                                </div>
                            </td>
                            <td class="px-3 py-1.5 text-end align-top" :class="data.equity.retainedEarnings < 0 ? 'text-red-600' : ''">{{ currency(data.equity.retainedEarnings) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-800">
                            <td class="px-3 py-2">Total Equity</td>
                            <td class="px-3 py-2 text-end">{{ currency(data.totalEquity) }}</td>
                        </tr>
                        <tr class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-800">
                            <td class="px-3 py-2">Total Liabilities + Equity</td>
                            <td class="px-3 py-2 text-end">{{ currency(data.totalLiabilitiesAndEquity) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <BalanceSheetDetailOffcanvas
            v-model="showDetailPanel"
            :title="detailTitle"
            :source="detailSource"
            :bank-id="detailBankId"
            :date="date"
        />
    </div>
</template>
