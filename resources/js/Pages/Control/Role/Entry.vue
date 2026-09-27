<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import AccessPanel from './AccessPanel.vue';

defineOptions({ layout: AppLayout });

const toast = useToast();

const form = reactive({ id: '', name: '' });
const rows = ref([]);
const search = ref('');
const onProgress = ref(false);
const showAccess = ref(false);
const selectedRole = ref(null);

function openAccess(row) {
    selectedRole.value = row;
    showAccess.value = true;
}

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return rows.value;
    return rows.value.filter((row) => String(row.name || '').toLowerCase().includes(term));
});

function load() {
    axios.post('/get-role').then((res) => {
        rows.value = res.data;
    });
}

function resetForm() {
    form.id = '';
    form.name = '';
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-role' : '/role';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { id: form.id, name: form.name });
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
    form.id = row.id;
    form.name = row.name;
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-role', { id });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Role Entry</h1>
            <form @submit.prevent="saveData" class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                    <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    {{ form.id == '' ? 'Save' : 'Update' }}
                </button>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filteredRows.length }} records</span>
                <input type="text" v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Added By</th>
                        <th class="px-3 py-2 font-medium">Updated By</th>
                        <th class="px-3 py-2 text-end font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in filteredRows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2">{{ row.name }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ row.ad_user?.username }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ row.up_user?.username }}</td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-3">
                                <button type="button" @click="openAccess(row)" title="Role Access" class="text-amber-500">
                                    <i class="bi bi-shield-lock"></i>
                                </button>
                                <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="filteredRows.length === 0">
                        <td colspan="4" class="px-3 py-6 text-center text-slate-400">No records found</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AccessPanel v-model="showAccess" :role="selectedRole" />
    </div>
</template>
