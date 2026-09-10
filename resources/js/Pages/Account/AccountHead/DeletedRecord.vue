<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { confirmDialog, errorMessageFrom } from '../../../lib/confirm';

defineOptions({ layout: AppLayout });

const toast = useToast();

const search = ref('');
const accountheads = ref([]);
const isLoading = ref(null);
const restoringId = ref(null);

async function showReport() {
    isLoading.value = false;
    try {
        const res = await axios.post('/get-deleted-accounthead', { search: search.value });
        accountheads.value = res.data;
    } catch (error) {
        toast.error(errorMessageFrom(error, 'Failed to load deleted account heads'));
    } finally {
        isLoading.value = true;
    }
}

async function restoreAccountHead(item) {
    const confirmed = await confirmDialog({
        title: 'Restore Account Head',
        text: `Restore account head "${item.name}"?`,
        icon: 'question',
        confirmButtonText: 'Restore',
    });
    if (!confirmed) return;

    restoringId.value = item.id;
    try {
        const res = await axios.post('/restore-accounthead', { id: item.id });
        if (res.data.status) {
            toast.success(res.data.message);
            accountheads.value = accountheads.value.filter((a) => a.id !== item.id);
        } else {
            toast.error(res.data.message || 'Failed to restore account head');
        }
    } catch (error) {
        toast.error(errorMessageFrom(error, 'Failed to restore account head'));
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
            <h1 class="text-lg font-semibold text-slate-800">Deleted AccountHead Record</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <form @submit.prevent="showReport" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                    <input
                        type="text"
                        v-model="search"
                        placeholder="Search by name"
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
                <span class="text-sm text-slate-500">{{ accountheads.length }} record(s) found</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">SL</th>
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Type</th>
                            <th class="px-2 py-2 font-medium">Deleted By</th>
                            <th class="px-2 py-2 font-medium">Deleted At</th>
                            <th class="px-2 py-2 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in accountheads" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ index + 1 }}</td>
                            <td class="px-2 py-1.5">{{ item.name }}</td>
                            <td class="px-2 py-1.5">{{ item.type }}</td>
                            <td class="px-2 py-1.5">{{ item.deleted_by_name }}</td>
                            <td class="px-2 py-1.5">{{ item.deleted_at }}</td>
                            <td class="px-2 py-1.5">
                                <button
                                    type="button"
                                    :disabled="restoringId === item.id"
                                    @click="restoreAccountHead(item)"
                                    class="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"
                                >
                                    <i class="bi bi-arrow-counterclockwise"></i> Restore
                                </button>
                            </td>
                        </tr>
                        <tr v-if="accountheads.length === 0">
                            <td colspan="6" class="px-2 py-6 text-center text-slate-400">No deleted account heads found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
