<script setup>
import { nextTick, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import TransactionInvoice from './TransactionInvoice.vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    transactionId: { type: [String, Number], default: null },
    transactionType: { type: String, default: 'expense' },
});

const emit = defineEmits(['update:modelValue']);
const page = usePage();
const designRef = ref(null);

const loading = ref(false);
const transaction = ref(null);
const username = ref('');

function close() {
    emit('update:modelValue', false);
}

function printTransaction() {
    designRef.value?.print();
}

let loadPromise = Promise.resolve();

async function loadTransaction(id) {
    if (!id) return;
    loading.value = true;
    try {
        const res = await axios.post('/get-transaction', { transactionId: id, type: props.transactionType });
        const t = res.data[0];
        transaction.value = t;
        username.value = t?.ad_user?.username;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.modelValue, props.transactionId],
    ([open, id]) => {
        if (open && id) loadPromise = loadTransaction(id);
    },
    { immediate: true }
);

async function printWhenReady() {
    await loadPromise;
    await nextTick();
    designRef.value?.print();
}

defineExpose({ print: printTransaction, printWhenReady });
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[320px] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-eye"></i> Invoice — {{ transaction?.invoice }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto bg-slate-100 p-5">
                    <div v-if="loading || !transaction" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
                    <TransactionInvoice v-else ref="designRef" :transaction="transaction" :username="username" :company="page.props.company" />
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3">
                    <button type="button" @click="close" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                    <button type="button" @click="printTransaction" class="inline-flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">
                        <i class="bi bi-printer"></i> Print
                    </button>
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
