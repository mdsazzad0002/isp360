<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    role: { type: String, default: '' },
});

const toast = useToast();

function emptyForm() {
    return { id: '', name: '', number: '', type: '', bank_name: '', branch_name: '', balance: 0, status: 'a' };
}

const form = reactive(emptyForm());
const rows = ref([]);
const search = ref('');
const filterType = ref('');
const onProgress = ref(false);

const balanceLocked = computed(() => form.id != '' && (props.role === 'user' || props.role === 'manager'));

const ACCOUNT_TYPE_HINTS = ['Savings', 'Current', 'Fixed Deposit', 'SND'];
const accountTypes = computed(() => {
    const fromRows = rows.value.map((row) => row.type).filter((t) => t && t.trim() !== '');
    return Array.from(new Set([...ACCOUNT_TYPE_HINTS, ...fromRows]));
});

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    return rows.value.filter((row) => {
        if (filterType.value && row.type !== filterType.value) return false;
        if (!term) return true;
        return JSON.stringify(row).toLowerCase().includes(term);
    });
});

function load() {
    axios.post('/get-bank').then((res) => {
        rows.value = res.data;
    });
}

function resetForm() {
    Object.assign(form, emptyForm());
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-bank' : '/bank';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { ...form });
        toast.success(res.data.message);
        resetForm();
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
        name: row.name,
        number: row.number,
        type: row.type,
        bank_name: row.bank_name,
        branch_name: row.branch_name,
        balance: row.balance,
        status: row.status,
    });
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-bank', { id });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

function exportExcel() {
    const params = new URLSearchParams();
    if (search.value) params.append('search', search.value);
    if (filterType.value) params.append('type', filterType.value);
    window.location.href = `/bank/export-excel?${params.toString()}`;
}

onMounted(load);
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Bank Entry</h1>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Number</label>
                        <input type="text" autocomplete="off" v-model="form.number" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                        <input type="text" autocomplete="off" list="bank-type-hints" placeholder="e.g. Savings, Current" v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        <datalist id="bank-type-hints">
                            <option v-for="t in accountTypes" :key="t" :value="t" />
                        </datalist>
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Bank Name</label>
                        <input type="text" autocomplete="off" v-model="form.bank_name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Branch Name</label>
                        <input type="text" autocomplete="off" v-model="form.branch_name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Balance</label>
                        <input type="number" min="0" step="any" :readonly="balanceLocked" v-model="form.balance" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" :class="balanceLocked ? 'bg-slate-50' : ''" />
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" true-value="a" false-value="p" v-model="form.status" />
                            Is Active
                        </label>
                        <div class="flex gap-2">
                            <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                            <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ form.id == '' ? 'Save' : 'Update' }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filteredRows.length }} records</span>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" v-model="search" placeholder="Search name, number, bank, branch..." class="w-64 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <select v-model="filterType" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">All Types</option>
                        <option v-for="t in accountTypes" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <button type="button" @click="exportExcel" title="Export Excel" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Account Name</th>
                            <th class="px-3 py-2 font-medium">Account Number</th>
                            <th class="px-3 py-2 font-medium">Account Type</th>
                            <th class="px-3 py-2 font-medium">Bank Name</th>
                            <th class="px-3 py-2 font-medium">Branch Name</th>
                            <th class="px-3 py-2 font-medium">Balance</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2">{{ row.name }}</td>
                            <td class="px-3 py-2">{{ row.number }}</td>
                            <td class="px-3 py-2">{{ row.type }}</td>
                            <td class="px-3 py-2">{{ row.bank_name }}</td>
                            <td class="px-3 py-2">{{ row.branch_name }}</td>
                            <td class="px-3 py-2">{{ row.balance }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="row.status === 'a' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ row.status === 'a' ? 'Active' : 'Deactive' }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="8" class="px-3 py-6 text-center text-slate-400">No records found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
