<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';

defineOptions({ layout: AppLayout });

const toast = useToast();
const notifications = ref([]);
const isLoading = ref(false);
const filterType = ref('');
const processingId = ref(null);

const typeLabels = {
    stock_transfer: 'Stock Transfer',
    low_stock: 'Low Stock',
};

function typeBadgeClass(type) {
    return {
        stock_transfer: 'bg-amber-100 text-amber-700',
        low_stock: 'bg-red-100 text-red-700',
    }[type] || 'bg-slate-100 text-slate-700';
}

function load() {
    isLoading.value = true;
    axios
        .post('/get-notifications', { type: filterType.value })
        .then((res) => {
            notifications.value = res.data;
        })
        .finally(() => {
            isLoading.value = false;
        });
}

const filteredCounts = computed(() => {
    const counts = { stock_transfer: 0, low_stock: 0 };
    notifications.value.forEach((n) => {
        if (counts[n.type] !== undefined) counts[n.type]++;
    });
    return counts;
});

function openNotification(row) {
    router.visit(row.link);
}

async function receiveTransfer(row) {
    if (!confirm(`Receive "${row.title}" and add its items to your branch stock?`)) return;
    processingId.value = row.id;
    try {
        const res = await axios.post('/receive-stock-transfer', { id: row.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        toast.error(err.response?.data?.message || 'Something went wrong');
    } finally {
        processingId.value = null;
    }
}

async function cancelTransfer(row) {
    const reason = prompt('Cancel reason (optional):') ?? '';
    processingId.value = row.id;
    try {
        const res = await axios.post('/cancel-stock-transfer', { id: row.id, reason });
        toast.success(res.data.message);
        load();
    } catch (err) {
        toast.error(err.response?.data?.message || 'Something went wrong');
    } finally {
        processingId.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-3xl p-4">
        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <h1 class="text-base font-semibold text-slate-800">Notifications</h1>
                <div class="flex rounded-md border border-slate-300 p-0.5 text-sm">
                    <button type="button" @click="filterType = ''; load()" class="rounded px-3 py-1" :class="filterType === '' ? 'bg-brand-500 text-white' : 'text-slate-600'">
                        All ({{ notifications.length }})
                    </button>
                    <button type="button" @click="filterType = 'stock_transfer'; load()" class="rounded px-3 py-1" :class="filterType === 'stock_transfer' ? 'bg-brand-500 text-white' : 'text-slate-600'">
                        Transfers ({{ filteredCounts.stock_transfer }})
                    </button>
                    <button type="button" @click="filterType = 'low_stock'; load()" class="rounded px-3 py-1" :class="filterType === 'low_stock' ? 'bg-brand-500 text-white' : 'text-slate-600'">
                        Low Stock ({{ filteredCounts.low_stock }})
                    </button>
                </div>
            </div>

            <div v-if="isLoading" class="p-6 text-center text-slate-400">Loading...</div>
            <div v-else-if="notifications.length === 0" class="p-10 text-center text-slate-400">
                <i class="bi bi-bell-slash mb-2 block text-2xl"></i>
                Nothing to show
            </div>

            <div v-else class="divide-y divide-slate-100">
                <div v-for="row in notifications" :key="row.type + '-' + row.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                    <div class="min-w-0 flex-1 cursor-pointer" @click="openNotification(row)">
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium" :class="typeBadgeClass(row.type)">{{ typeLabels[row.type] ?? row.type }}</span>
                            <span class="truncate text-sm font-medium text-slate-800">{{ row.title }}</span>
                        </div>
                        <div class="mt-0.5 text-xs text-slate-500">{{ row.subtitle }}</div>
                    </div>

                    <div v-if="row.type === 'stock_transfer'" class="flex shrink-0 gap-2">
                        <button type="button" :disabled="processingId === row.id" @click="receiveTransfer(row)" class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            Receive
                        </button>
                        <button type="button" :disabled="processingId === row.id" @click="cancelTransfer(row)" class="rounded-md bg-red-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50">
                            Cancel
                        </button>
                    </div>
                    <div v-else-if="row.type === 'low_stock'" class="shrink-0">
                        <button type="button" @click="openNotification(row)" class="rounded-md bg-brand-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-600">
                            Create Purchase
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
