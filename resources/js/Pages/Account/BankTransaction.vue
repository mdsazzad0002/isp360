<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import axios from 'axios';
import SearchSelect from '../../Components/SearchSelect.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    invoice: { type: String, required: true },
});

const toast = useToast();

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function emptyForm() {
    return { id: '', invoice: props.invoice, date: todayStr(), type: 'credit', amount: 0, previous_balance: 0, note: '' };
}

const form = reactive(emptyForm());
const banks = ref([]);
const selectedBank = ref(null);
const rows = ref([]);
const search = ref('');
const onProgress = ref(false);

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return rows.value;
    return rows.value.filter((row) => JSON.stringify(row).toLowerCase().includes(term));
});

function getBanks() {
    axios.post('/get-bank').then((res) => {
        banks.value = res.data;
    });
}

function load() {
    axios.post('/get-bankTransaction', { dateFrom: form.date, dateTo: form.date }).then((res) => {
        rows.value = res.data.map((item) => ({
            ...item,
            name: `${item.bank?.name} - ${item.bank?.number} - ${item.bank?.bank_name}`,
        }));
    });
}

async function onChangeBank(val) {
    selectedBank.value = val;
    if (val == null) return;
    if (val.id != '') {
        const res = await axios.post('/get-bankBalance', { bankId: val.id });
        form.previous_balance = res.data[0].currentbalance;
    }
}

function resetForm() {
    Object.assign(form, emptyForm());
    selectedBank.value = null;
    onProgress.value = false;
}

async function saveData() {
    if (!(Number(form.amount) > 0)) {
        toast.error('Amount must be greater than 0');
        return;
    }
    const url = form.id != '' ? '/update-bankTransaction' : '/bankTransaction';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { ...form, bank_id: selectedBank.value ? selectedBank.value.id : '' });
        toast.success(res.data.message);
        resetForm();
        form.invoice = res.data.invoice;
        load();
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
    Object.assign(form, {
        id: row.id,
        invoice: row.invoice,
        date: row.date,
        type: row.type,
        amount: row.amount,
        previous_balance: row.previous_balance,
        note: row.note,
    });
    selectedBank.value = banks.value.find((item) => item.id == row.bank_id) ?? null;
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-bankTransaction', { id, type: 'transaction' });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

onMounted(() => {
    getBanks();
    load();
});
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Bank Transaction Entry</h1>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Invoice</label>
                        <input type="text" readonly :value="form.invoice" class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Account</label>
                        <SearchSelect :options="banks" v-model="selectedBank" label="display_name" placeholder="Select account" @update:model-value="onChangeBank" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                        <input type="date" v-model="form.date" @change="load" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Prev. Balance</label>
                        <input type="number" step="any" readonly :value="form.previous_balance" class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                        <select v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="debit">Withdraw</option>
                            <option value="credit">Deposit</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input type="text" v-model="form.note" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Amount</label>
                        <input type="number" step="any" min="0.01" v-model="form.amount" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
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

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filteredRows.length }} records</span>
                <input type="text" v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Invoice</th>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Bank</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Amount</th>
                            <th class="px-3 py-2 font-medium">Note</th>
                            <th class="px-3 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2">{{ row.invoice }}</td>
                            <td class="px-3 py-2">{{ row.date }}</td>
                            <td class="px-3 py-2">{{ row.name }}</td>
                            <td class="px-3 py-2 capitalize">{{ row.type }}</td>
                            <td class="px-3 py-2">{{ row.amount }}</td>
                            <td class="px-3 py-2">{{ row.note }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">No records found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
