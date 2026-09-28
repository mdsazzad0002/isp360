<script setup>
import { moneyDecimals, today } from '../../lib/isp';
import { reactive, ref, computed, onMounted } from 'vue';
import axios from 'axios';
import SearchSelect from '../../Components/SearchSelect.vue';
import QuickEntryAccountHead from '../../Components/QuickEntryAccountHead.vue';
import TransactionQuickView from './TransactionQuickView.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { printDocument } from '../../lib/print';
import { usePage } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: { type: String, required: true },
    type: { type: String, required: true },
    invoice: { type: String, required: true },
});

const toast = useToast();
const page = usePage();

function todayStr() {
    const d = new Date(today() + 'T00:00:00');
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const activeType = ref(props.type);
const form = reactive({ id: '', invoice: props.invoice, date: todayStr(), note: '', amount: 0 });
const accounts = ref([]);
const selectedAccount = ref(null);
const rows = ref([]);
const search = ref('');
const dateFrom = ref(todayStr());
const dateTo = ref(todayStr());
const onProgress = ref(false);
const showQuickView = ref(false);
const quickViewId = ref(null);
const quickViewType = ref(props.type);

function viewInvoice(row) {
    quickViewId.value = row.id;
    quickViewType.value = row.type;
    showQuickView.value = true;
}

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return rows.value;
    return rows.value.filter((row) => JSON.stringify(row).toLowerCase().includes(term));
});

const totalAmount = computed(() => filteredRows.value.reduce((pr, cu) => pr + parseFloat(cu.amount || 0), 0).toFixed(moneyDecimals()));

function getAccounts() {
    axios.post('/get-accounthead', { type: activeType.value }).then((res) => {
        accounts.value = res.data;
    });
}

function onAccountCreated({ list, account }) {
    accounts.value = list;
    if (account) selectedAccount.value = account;
}

function load() {
    axios
        .post('/get-transaction', { dateFrom: dateFrom.value, dateTo: dateTo.value, type: activeType.value })
        .then((res) => {
            rows.value = res.data;
        });
}

async function switchType(newType) {
    if (activeType.value === newType) return;
    activeType.value = newType;
    resetForm();
    const res = await axios.post('/get-transaction-invoice', { type: newType });
    form.invoice = res.data.invoice;
    getAccounts();
    load();
}

function resetForm() {
    form.id = '';
    form.invoice = props.invoice;
    form.date = todayStr();
    form.note = '';
    form.amount = 0;
    selectedAccount.value = null;
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-transaction' : '/transaction';
    onProgress.value = true;
    try {
        const res = await axios.post(url, {
            id: form.id,
            invoice: form.invoice,
            date: form.date,
            note: form.note,
            amount: form.amount,
            type: activeType.value,
            account_id: selectedAccount.value ? selectedAccount.value.id : '',
        });
        toast.success(res.data.message);
        const savedId = res.data.id ?? form.id;
        const savedType = activeType.value;
        resetForm();
        form.invoice = res.data.invoice;
        load();
        if (savedId) {
            quickViewId.value = savedId;
            quickViewType.value = savedType;
            showQuickView.value = true;
        }
    } catch (err) {
        onProgress.value = false;
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    }
}

function editRow(row) {
    form.id = row.id;
    form.invoice = row.invoice;
    form.date = row.date;
    form.note = row.note;
    form.amount = row.amount;
    selectedAccount.value = accounts.value.find((item) => item.id == row.account_id) ?? null;
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-transaction', { id, type: activeType.value });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

function exportExcel() {
    const params = new URLSearchParams({ type: activeType.value, dateFrom: dateFrom.value, dateTo: dateTo.value });
    if (search.value !== '') params.append('search', search.value);
    window.location.href = `/transaction/export-excel?${params.toString()}`;
}

function print() {
    const label = activeType.value === 'income' ? 'Income' : 'Expense';
    const rowsHtml = filteredRows.value.length
        ? filteredRows.value
              .map(
                  (row, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>${row.invoice}</td>
                    <td>${row.date}</td>
                    <td>${row.account?.name ?? ''}</td>
                    <td style="text-align:right;">${row.amount}</td>
                    <td>${row.note ?? ''}</td>
                </tr>`
              )
              .join('') + `<tr><th colspan="4" style="text-align:right;">Total</th><th style="text-align:right;">${totalAmount.value}</th><th></th></tr>`
        : '<tr><td colspan="6" style="text-align:center;">Not Found Data</td></tr>';

    const bodyHtml = `<table><thead><tr><th>Sl</th><th>Invoice</th><th>Date</th><th>Account</th><th>Amount</th><th>Note</th></tr></thead><tbody>${rowsHtml}</tbody></table>`;
    printDocument(`${label} Record`, bodyHtml, page.props.company);
}

onMounted(() => {
    // Business Info links here with the date range the figure it showed
    // actually covers (e.g. all-time for the Income/Expense totals), so the
    // page should open already filtered instead of defaulting to today.
    const params = new URLSearchParams(window.location.search);
    if (params.has('dateFrom')) dateFrom.value = params.get('dateFrom');
    if (params.has('dateTo')) dateTo.value = params.get('dateTo');
    getAccounts();
    load();
});
</script>

<template>
    <div class="mx-auto space-y-3 p-4">
        <!-- Type tabs -->
        <div class="flex gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
            <button
                type="button"
                @click="switchType('income')"
                class="flex-1 rounded-md py-2 text-sm font-semibold transition"
                :class="activeType === 'income' ? 'bg-emerald-500 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-50'"
            >
                <i class="bi bi-duffle"></i> Income
            </button>
            <button
                type="button"
                @click="switchType('expense')"
                class="flex-1 rounded-md py-2 text-sm font-semibold transition"
                :class="activeType === 'expense' ? 'bg-red-500 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-50'"
            >
                <i class="bi bi-clipboard-minus"></i> Expense
            </button>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ activeType === 'income' ? 'Income Entry' : 'Expense Entry' }}</h1>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Invoice</label>
                        <input type="text" readonly :value="form.invoice" class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Account</label>
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <SearchSelect :options="accounts" v-model="selectedAccount" label="name" placeholder="Select account" />
                            </div>
                            <QuickEntryAccountHead :type="activeType" @created="onAccountCreated" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                        <input type="date" v-model="form.date" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input type="text" v-model="form.note" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Amount</label>
                        <input type="number" step="any" min="0" v-model="form.amount" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                        <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                            {{ form.id == '' ? 'Save' : 'Update' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date From</label>
                        <input type="date" v-model="dateFrom" @change="load" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date To</label>
                        <input type="date" v-model="dateTo" @change="load" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input type="text" v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-slate-500">{{ filteredRows.length }} records</span>
                    <button type="button" @click="exportExcel" title="Export Excel" class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-500 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" @click="print" title="Print" class="text-slate-500 hover:text-brand-500">
                        <i class="bi bi-printer text-lg"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Invoice</th>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Account</th>
                            <th class="px-3 py-2 font-medium">Amount</th>
                            <th class="px-3 py-2 font-medium">Note</th>
                            <th class="px-3 py-2 font-medium">Added By</th>
                            <th class="px-3 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2">{{ row.invoice }}</td>
                            <td class="px-3 py-2">{{ row.date }}</td>
                            <td class="px-3 py-2">{{ row.account?.name }}</td>
                            <td class="px-3 py-2">{{ row.amount }}</td>
                            <td class="px-3 py-2">{{ row.note }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ row.ad_user?.username }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i @click="viewInvoice(row)" title="View Invoice" class="bi bi-file-earmark-medical-fill cursor-pointer text-slate-500 hover:text-slate-700"></i>
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">No records found</td>
                        </tr>
                        <tr v-else class="bg-slate-50 font-semibold">
                            <td colspan="3" class="px-3 py-2 text-end">Total</td>
                            <td class="px-3 py-2">{{ totalAmount }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <TransactionQuickView v-model="showQuickView" :transaction-id="quickViewId" :transaction-type="quickViewType" />
    </div>
</template>
