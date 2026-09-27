<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SearchSelect from '../../Components/SearchSelect.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const props = defineProps({
    mode: { type: String, required: true }, // 'bank' | 'cash'
});

const page = usePage();
const title = props.mode === 'bank' ? 'Bank Ledger' : 'Cash Ledger';
const inLabel = props.mode === 'bank' ? 'Deposit' : 'In Amount';
const outLabel = props.mode === 'bank' ? 'Withdraw' : 'Out Amount';
const inField = props.mode === 'bank' ? 'deposit' : 'in_amount';
const outField = props.mode === 'bank' ? 'withdraw' : 'out_amount';
const ledgerUrl = props.mode === 'bank' ? '/get-bank-ledger' : '/get-cash-ledger';

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const dateFrom = ref(todayStr());
const dateTo = ref(todayStr());
const ledgers = ref([]);
const previousBalance = ref(0);
const banks = ref([]);
const selectedBank = ref(null);
const isLoading = ref(null);

function getBanks() {
    axios.post('/get-bank').then((res) => (banks.value = res.data));
}

function showLedger() {
    isLoading.value = false;
    axios
        .post(ledgerUrl, {
            bankId: selectedBank.value ? selectedBank.value.id : '',
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
    let bankText = '';
    if (props.mode === 'bank' && selectedBank.value) {
        bankText = `
            <p><strong>Bank ID:</strong> ${selectedBank.value.code}</p>
            <p><strong>Name:</strong> ${selectedBank.value.name}</p>
            <p><strong>Mobile:</strong> ${selectedBank.value.phone}</p>`;
    }
    const dateText = `<p><strong>Statement From:</strong> ${dateFrom.value} to ${dateTo.value}</p>`;

    const bankCol = props.mode === 'bank';
    const rowsHtml =
        `<tr><td></td><td colspan="${bankCol ? 4 : 3}">Previous Balance</td><td style="text-align:right;">${previousBalance.value}</td></tr>` +
        ledgers.value
            .map(
                (item) => `
            <tr>
                <td>${item.date}</td>
                <td>${item.description ?? ''}</td>
                ${bankCol ? `<td>${item.bank_name ?? ''}</td>` : ''}
                <td style="text-align:right;">${item[inField]}</td>
                <td style="text-align:right;">${item[outField]}</td>
                <td style="text-align:right;">${parseFloat(item.balance).toFixed(2)}</td>
            </tr>`
            )
            .join('') +
        (ledgers.value.length
            ? `<tr><th colspan="${bankCol ? 3 : 2}" style="text-align:center;">Total</th><th style="text-align:right;">${sumField(inField)}</th><th style="text-align:right;">${sumField(outField)}</th><th style="text-align:right;">${lastBalance()}</th></tr>`
            : '');

    const bodyHtml = `
        ${bankText}
        ${dateText}
        <table>
            <thead><tr><th>Date</th><th>Description</th>${bankCol ? '<th>Bank</th>' : ''}<th>${inLabel}</th><th>${outLabel}</th><th>Balance</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument(title, bodyHtml, page.props.company);
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('dateFrom')) dateFrom.value = params.get('dateFrom');
    if (params.get('dateTo')) dateTo.value = params.get('dateTo');

    if (props.mode === 'bank') {
        const bankId = params.get('bankId');
        axios.post('/get-bank').then((res) => {
            banks.value = res.data;
            if (bankId) selectedBank.value = banks.value.find((b) => String(b.id) === String(bankId)) || null;
            showLedger();
        });
    } else {
        showLedger();
    }
});
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <form @submit.prevent="showLedger" class="flex flex-wrap items-end gap-3">
                <div v-if="mode === 'bank'" class="w-64">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Bank</label>
                    <SearchSelect :options="banks" v-model="selectedBank" label="display_name" placeholder="All Banks" />
                </div>
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
                            <th v-if="mode === 'bank'" class="px-2 py-2 font-medium">Bank</th>
                            <th class="px-2 py-2 font-medium">{{ inLabel }}</th>
                            <th class="px-2 py-2 font-medium">{{ outLabel }}</th>
                            <th class="px-2 py-2 font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="px-2 py-1.5"></td>
                            <td :colspan="mode === 'bank' ? 4 : 3" class="px-2 py-1.5">Previous Balance</td>
                            <td class="px-2 py-1.5 text-end">{{ previousBalance }}</td>
                        </tr>
                        <tr v-for="(item, index) in ledgers" :key="index" class="border-b border-slate-100">
                            <td class="px-2 py-1.5">{{ item.date }}</td>
                            <td class="px-2 py-1.5">{{ item.description }}</td>
                            <td v-if="mode === 'bank'" class="px-2 py-1.5">{{ item.bank_name }}</td>
                            <td class="px-2 py-1.5 text-end">{{ item[inField] }}</td>
                            <td class="px-2 py-1.5 text-end">{{ item[outField] }}</td>
                            <td class="px-2 py-1.5 text-end">{{ parseFloat(item.balance).toFixed(2) }}</td>
                        </tr>
                        <tr v-if="ledgers.length > 0" class="bg-slate-50 font-semibold">
                            <td :colspan="mode === 'bank' ? 3 : 2" class="px-2 py-2 text-center">Total</td>
                            <td class="px-2 py-2 text-end">{{ sumField(inField) }}</td>
                            <td class="px-2 py-2 text-end">{{ sumField(outField) }}</td>
                            <td class="px-2 py-2 text-end">{{ lastBalance() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
