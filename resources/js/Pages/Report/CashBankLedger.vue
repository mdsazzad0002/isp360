<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const page = usePage();
const title = 'Cash & Bank Ledger';

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const dateFrom = ref(todayStr());
const dateTo = ref(todayStr());
const ledgers = ref([]);
const previousBalance = ref(0);
const isLoading = ref(null);

function showLedger() {
    isLoading.value = false;
    const filter = { dateFrom: dateFrom.value, dateTo: dateTo.value };

    Promise.all([axios.post('/get-cash-ledger', filter), axios.post('/get-bank-ledger', { ...filter, bankId: '' })]).then(([cashRes, bankRes]) => {
        const cashLedgers = cashRes.data.ledgers.map((item) => ({
            date: item.date,
            created_at: item.created_at,
            description: item.description,
            source: 'Cash',
            in: parseFloat(item.in_amount) || 0,
            out: parseFloat(item.out_amount) || 0,
        }));
        const bankLedgers = bankRes.data.ledgers.map((item) => ({
            date: item.date,
            created_at: item.created_at,
            description: item.description,
            source: 'Bank',
            in: parseFloat(item.deposit) || 0,
            out: parseFloat(item.withdraw) || 0,
        }));

        const combined = [...cashLedgers, ...bankLedgers].sort((a, b) => {
            if (a.date !== b.date) return a.date < b.date ? -1 : 1;
            return new Date(a.created_at) - new Date(b.created_at);
        });

        const startBalance = (parseFloat(cashRes.data.previousBalance) || 0) + (parseFloat(bankRes.data.previousBalance) || 0);
        let running = startBalance;
        ledgers.value = combined.map((item) => {
            running = running + item.in - item.out;
            return { ...item, balance: running };
        });
        previousBalance.value = startBalance;
        isLoading.value = true;
    });
}

function sumField(field) {
    return ledgers.value.reduce((pre, cur) => pre + cur[field], 0).toFixed(2);
}

function lastBalance() {
    return ledgers.value.length ? ledgers.value[ledgers.value.length - 1].balance.toFixed(2) : '0.00';
}

function print() {
    const dateText = `<p><strong>Statement From:</strong> ${dateFrom.value} to ${dateTo.value}</p>`;

    const rowsHtml =
        `<tr><td></td><td colspan="3">Previous Balance</td><td style="text-align:right;">${previousBalance.value.toFixed(2)}</td></tr>` +
        ledgers.value
            .map(
                (item) => `
            <tr>
                <td>${item.date}</td>
                <td>${item.description ?? ''} <em>(${item.source})</em></td>
                <td style="text-align:right;">${item.in.toFixed(2)}</td>
                <td style="text-align:right;">${item.out.toFixed(2)}</td>
                <td style="text-align:right;">${item.balance.toFixed(2)}</td>
            </tr>`
            )
            .join('') +
        (ledgers.value.length
            ? `<tr><th colspan="2" style="text-align:center;">Total</th><th style="text-align:right;">${sumField('in')}</th><th style="text-align:right;">${sumField('out')}</th><th style="text-align:right;">${lastBalance()}</th></tr>`
            : '');

    const bodyHtml = `
        ${dateText}
        <table>
            <thead><tr><th>Date</th><th>Description</th><th>In</th><th>Out</th><th>Balance</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument(title, bodyHtml, page.props.company);
}

onMounted(showLedger);
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <form @submit.prevent="showLedger" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
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
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Date</th>
                            <th class="px-2 py-2 font-medium">Description</th>
                            <th class="px-2 py-2 font-medium">Source</th>
                            <th class="px-2 py-2 font-medium">In</th>
                            <th class="px-2 py-2 font-medium">Out</th>
                            <th class="px-2 py-2 font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="px-2 py-1.5"></td>
                            <td colspan="4" class="px-2 py-1.5">Previous Balance</td>
                            <td class="px-2 py-1.5 text-end">{{ previousBalance.toFixed(2) }}</td>
                        </tr>
                        <tr v-for="(item, index) in ledgers" :key="index" class="border-b border-slate-100">
                            <td class="px-2 py-1.5">{{ item.date }}</td>
                            <td class="px-2 py-1.5">{{ item.description }}</td>
                            <td class="px-2 py-1.5">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="item.source === 'Cash' ? 'bg-emerald-100 text-emerald-700' : 'bg-brand-100 text-brand-700'">
                                    {{ item.source }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5 text-end">{{ item.in.toFixed(2) }}</td>
                            <td class="px-2 py-1.5 text-end">{{ item.out.toFixed(2) }}</td>
                            <td class="px-2 py-1.5 text-end">{{ item.balance.toFixed(2) }}</td>
                        </tr>
                        <tr v-if="ledgers.length > 0" class="bg-slate-50 font-semibold">
                            <td colspan="3" class="px-2 py-2 text-center">Total</td>
                            <td class="px-2 py-2 text-end">{{ sumField('in') }}</td>
                            <td class="px-2 py-2 text-end">{{ sumField('out') }}</td>
                            <td class="px-2 py-2 text-end">{{ lastBalance() }}</td>
                        </tr>
                        <tr v-if="ledgers.length === 0">
                            <td colspan="6" class="px-2 py-6 text-center text-slate-400">Not Found Data</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
