<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog, errorMessageFrom } from '../../lib/confirm';

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: { type: String, required: true },
    slug: { type: String, required: true },
});

const toast = useToast();

const search = ref('');
const rows = ref([]);
const isLoading = ref(null);
const restoringId = ref(null);

async function showReport() {
    isLoading.value = false;
    try {
        const res = await axios.post(`/get-deleted-${props.slug}`);
        rows.value = res.data;
    } catch (error) {
        toast.error(errorMessageFrom(error, 'Failed to load deleted records'));
    } finally {
        isLoading.value = true;
    }
}

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return rows.value;
    return rows.value.filter((row) => String(row.name || '').toLowerCase().includes(term));
});

async function restoreRow(item) {
    const confirmed = await confirmDialog({
        title: 'Restore Record',
        text: `Restore "${item.name}"?`,
        icon: 'question',
        confirmButtonText: 'Restore',
    });
    if (!confirmed) return;

    restoringId.value = item.id;
    try {
        const res = await axios.post(`/restore-${props.slug}`, { id: item.id });
        if (res.data.status) {
            toast.success(res.data.message);
            rows.value = rows.value.filter((r) => r.id !== item.id);
        } else {
            toast.error(res.data.message || 'Failed to restore record');
        }
    } catch (error) {
        toast.error(errorMessageFrom(error, 'Failed to restore record'));
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
            <h1 class="text-lg font-semibold text-slate-800">{{ title }}</h1>
        </div>

        <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                <span class="text-sm text-slate-500">{{ filteredRows.length }} record(s) found</span>
                <input type="text" v-model="search" placeholder="Search by name..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">SL</th>
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Deleted By</th>
                            <th class="px-2 py-2 font-medium">Deleted At</th>
                            <th class="px-2 py-2 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in filteredRows" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ index + 1 }}</td>
                            <td class="px-2 py-1.5">{{ item.name }}</td>
                            <td class="px-2 py-1.5">{{ item.deleted_by_name }}</td>
                            <td class="px-2 py-1.5">{{ item.deleted_at }}</td>
                            <td class="px-2 py-1.5">
                                <button
                                    type="button"
                                    :disabled="restoringId === item.id"
                                    @click="restoreRow(item)"
                                    class="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"
                                >
                                    <i class="bi bi-arrow-counterclockwise"></i> Restore
                                </button>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="5" class="px-2 py-6 text-center text-slate-400">No deleted records found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
