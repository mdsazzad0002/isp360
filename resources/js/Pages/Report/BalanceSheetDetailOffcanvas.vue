<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    // 'cash' | 'bank' -> reuses the Day Book per-amount endpoints (closing balance as of `date`)
    // anything else -> /get-balanceSheet-detail with that string as `section`
    source: { type: String, default: '' },
    bankId: { type: [String, Number], default: '' },
    date: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const loading = ref(false);
const loaded = ref(false);
const result = ref(null);
const search = ref('');

function close() {
    emit('update:modelValue', false);
}

async function load() {
    if (!props.source) return;
    loading.value = true;
    loaded.value = false;
    search.value = '';
    try {
        let res;
        if (props.source === 'cash') {
            res = await axios.post('/get-dayBook-cash-detail', { section: 'closing', dateFrom: props.date, dateTo: props.date });
        } else if (props.source === 'bank') {
            res = await axios.post('/get-dayBook-bank-detail', { section: 'closing', dateFrom: props.date, dateTo: props.date, bankId: props.bankId });
        } else {
            res = await axios.post('/get-balanceSheet-detail', { section: props.source, date: props.date });
        }
        result.value = res.data;
        loaded.value = true;
    } finally {
        loading.value = false;
    }
}

const filteredRows = computed(() => {
    if (!result.value) return [];
    const term = search.value.trim().toLowerCase();
    if (!term) return result.value.rows;
    return result.value.rows.filter((row) => (row.label ?? '').toLowerCase().includes(term) || (row.sublabel ?? '').toLowerCase().includes(term));
});

function total() {
    if (!result.value) return '0.00';
    if (result.value.type === 'breakdown') return Number(result.value.total || 0).toFixed(2);
    return filteredRows.value.reduce((pre, cur) => pre + parseFloat(cur.amount || 0), 0).toFixed(2);
}

watch(
    () => [props.modelValue, props.source, props.bankId, props.date],
    ([open]) => {
        if (open) load();
    },
    { immediate: true }
);
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[80%] min-w-[320px] max-w-2xl flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-800"><i class="bi bi-calculator"></i> {{ title }}</h2>
                        <p class="text-xs text-slate-500">As of {{ date }}</p>
                    </div>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <div v-if="loading" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
                    <template v-else-if="loaded && result">
                        <!-- Breakdown: component amounts that add up to this line's total -->
                        <div v-if="result.type === 'breakdown'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                        <th class="px-2 py-2 font-medium">Component</th>
                                        <th class="px-2 py-2 font-medium">Type</th>
                                        <th class="px-2 py-2 text-right font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, index) in result.rows" :key="index" class="border-b border-slate-100">
                                        <td class="px-2 py-1.5">{{ row.label }}</td>
                                        <td class="px-2 py-1.5">
                                            <span :class="row.direction === 'in' ? 'text-emerald-600' : 'text-red-600'">{{ row.direction === 'in' ? '+ In' : '− Out' }}</span>
                                        </td>
                                        <td class="px-2 py-1.5 text-right">{{ parseFloat(row.amount).toFixed(2) }}</td>
                                    </tr>
                                    <tr v-if="result.rows.length === 0">
                                        <td colspan="3" class="px-2 py-6 text-center text-slate-400">No contributing entries found</td>
                                    </tr>
                                    <tr class="bg-slate-50 font-semibold">
                                        <td colspan="2" class="px-2 py-2 text-right">Total</td>
                                        <td class="px-2 py-2 text-right">{{ total() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- List: the individual customers/suppliers/products/records behind this line -->
                        <template v-else>
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <input type="text" v-model="search" placeholder="Search..." class="w-full max-w-xs rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                                <span class="whitespace-nowrap text-xs text-slate-500">{{ filteredRows.length }} of {{ result.rows.length }} items</span>
                            </div>
                            <div class="max-h-[70vh] overflow-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 z-10">
                                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                            <th class="px-2 py-2 font-medium">Name</th>
                                            <th class="px-2 py-2 font-medium">Reference</th>
                                            <th class="px-2 py-2 text-right font-medium">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, index) in filteredRows" :key="index" class="border-b border-slate-100">
                                            <td class="px-2 py-1.5">{{ row.label }}</td>
                                            <td class="px-2 py-1.5 text-slate-500">{{ row.sublabel }}</td>
                                            <td class="px-2 py-1.5 text-right">{{ parseFloat(row.amount).toFixed(2) }}</td>
                                        </tr>
                                        <tr v-if="filteredRows.length === 0">
                                            <td colspan="3" class="px-2 py-6 text-center text-slate-400">No records found</td>
                                        </tr>
                                        <tr v-if="filteredRows.length > 0" class="sticky bottom-0 bg-slate-50 font-semibold">
                                            <td colspan="2" class="px-2 py-2 text-right">Total</td>
                                            <td class="px-2 py-2 text-right">{{ total() }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </template>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3">
                    <button type="button" @click="close" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
.animate-slide-in {
    animation: slide-in 0.25s ease-out;
}
</style>
