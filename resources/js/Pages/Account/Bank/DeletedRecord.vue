<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { confirmDialog, errorMessageFrom } from '../../../lib/confirm';

defineOptions({ layout: AppLayout });

const toast = useToast();

const search = ref('');
const banks = ref([]);
const isLoading = ref(null);
const restoringId = ref(null);

async function showReport() {
    isLoading.value = false;
    try {
        const res = await axios.post('/get-deleted-bank', { search: search.value });
        banks.value = res.data;
    } catch (error) {
        toast.error(errorMessageFrom(error, 'Failed to load deleted banks'));
    } finally {
        isLoading.value = true;
    }
}

async function restoreBank(item) {
    const confirmed = await confirmDialog({
        title: 'Restore Bank',
        text: `Restore bank account "${item.name}"?`,
        icon: 'question',
        confirmButtonText: 'Restore',
    });
    if (!confirmed) return;

    restoringId.value = item.id;
    try {
        const res = await axios.post('/restore-bank', { id: item.id });
        if (res.data.status) {
            toast.success(res.data.message);
            banks.value = banks.value.filter((b) => b.id !== item.id);
        } else {
            toast.error(res.data.message || 'Failed to restore bank');
        }
    } catch (error) {
        toast.error(errorMessageFrom(error, 'Failed to restore bank'));
    } finally {
        restoringId.value = null;
    }
}

onMounted(showReport);
</script>

<template>
    <div class="mx-auto p-4">
        <div class="mb-3 flex items-center gap-2">
            <i class="bi bi-arrow-counterclockwise text-xl text-brand-500"></i>
            <h1 class="text-lg font-semibold text-slate-800">Deleted Bank Record</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <form @submit.prevent="showReport" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Name / Number / Bank</label>
                    <input
                        type="text"
                        v-model="search"
                        placeholder="Search by name, number or bank name"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400"
                    />
                </div>
                <button type="submit" class="flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">
                    <i class="bi bi-search"></i> Show
                </button>
            </form>
        </div>

        <div v-if="isLoading" class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                <span class="text-sm text-slate-500">{{ banks.length }} record(s) found</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">SL</th>
                            <th class="px-2 py-2 font-medium">Account Name</th>
                            <th class="px-2 py-2 font-medium">Account Number</th>
                            <th class="px-2 py-2 font-medium">Bank Name</th>
                            <th class="px-2 py-2 font-medium">Balance</th>
                            <th class="px-2 py-2 font-medium">Deleted By</th>
                            <th class="px-2 py-2 font-medium">Deleted At</th>
                            <th class="px-2 py-2 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in banks" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ index + 1 }}</td>
                            <td class="px-2 py-1.5">{{ item.name }}</td>
                            <td class="px-2 py-1.5">{{ item.number }}</td>
                            <td class="px-2 py-1.5">{{ item.bank_name }}</td>
                            <td class="px-2 py-1.5">{{ item.balance }}</td>
                            <td class="px-2 py-1.5">{{ item.deleted_by_name }}</td>
                            <td class="px-2 py-1.5">{{ item.deleted_at }}</td>
                            <td class="px-2 py-1.5">
                                <button
                                    type="button"
                                    :disabled="restoringId === item.id"
                                    @click="restoreBank(item)"
                                    class="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"
                                >
                                    <i class="bi bi-arrow-counterclockwise"></i> Restore
                                </button>
                            </td>
                        </tr>
                        <tr v-if="banks.length === 0">
                            <td colspan="8" class="px-2 py-6 text-center text-slate-400">No deleted banks found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
