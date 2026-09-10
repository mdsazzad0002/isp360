<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { printDocument } from '../../lib/print';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    recordId: { type: [String, Number], default: null },
    mode: { type: String, default: 'receive' }, // 'receive' | 'payment'
});

const emit = defineEmits(['update:modelValue']);
const page = usePage();

const loading = ref(false);
const record = ref(null);

const label = computed(() => (props.mode === 'payment' ? 'Payment' : 'Receive'));
const endpoint = computed(() => `/get-${props.mode}-record`);
const partyLabel = computed(() => (record.value?.type === 'supplier' ? 'Supplier' : 'Customer'));
const party = computed(() => (record.value?.type === 'supplier' ? record.value?.supplier : record.value?.customer));

function close() {
    emit('update:modelValue', false);
}

let loadPromise = Promise.resolve();

async function loadRecord(id) {
    if (!id) return;
    loading.value = true;
    try {
        const res = await axios.post(endpoint.value, { id });
        record.value = res.data;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.modelValue, props.recordId],
    ([open, id]) => {
        if (open && id) loadPromise = loadRecord(id);
    },
    { immediate: true }
);

function receiptHtml() {
    const r = record.value;
    if (!r) return '';
    const p = party.value;
    const bankLine = r.payment_method === 'bank' && r.bank ? `<tr><td style="padding:4px 0;color:#64748b;">Account</td><td style="padding:4px 0;text-align:right;">${r.bank.bank_name ?? ''} ${r.bank.name ?? ''} (${r.bank.number ?? ''})</td></tr>` : '';
    return `
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <tr><td style="padding:4px 0;color:#64748b;">Invoice</td><td style="padding:4px 0;text-align:right;font-weight:600;">${r.invoice}</td></tr>
            <tr><td style="padding:4px 0;color:#64748b;">Date</td><td style="padding:4px 0;text-align:right;">${r.date}</td></tr>
            <tr><td style="padding:4px 0;color:#64748b;">${partyLabel.value}</td><td style="padding:4px 0;text-align:right;">${p?.name ?? ''}${p?.code ? ` (${p.code})` : ''}</td></tr>
            <tr><td style="padding:4px 0;color:#64748b;">Phone</td><td style="padding:4px 0;text-align:right;">${p?.phone ?? ''}</td></tr>
            <tr><td style="padding:4px 0;color:#64748b;">Method</td><td style="padding:4px 0;text-align:right;text-transform:capitalize;">${r.payment_method}</td></tr>
            ${bankLine}
            <tr><td style="padding:4px 0;color:#64748b;">Previous Due</td><td style="padding:4px 0;text-align:right;">${r.previous_due}</td></tr>
            <tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;font-weight:700;">Amount</td><td style="padding:8px 0;border-top:1px solid #e2e8f0;text-align:right;font-weight:700;">${r.amount}</td></tr>
            ${r.note ? `<tr><td style="padding:4px 0;color:#64748b;">Note</td><td style="padding:4px 0;text-align:right;">${r.note}</td></tr>` : ''}
            <tr><td style="padding:4px 0;color:#64748b;">Received By</td><td style="padding:4px 0;text-align:right;">${r.ad_user?.name ?? ''}</td></tr>
        </table>
    `;
}

function printRecord() {
    if (!record.value) return;
    printDocument(`${label.value} Voucher`, receiptHtml(), page.props.company);
}

async function printWhenReady() {
    await loadPromise;
    printRecord();
}

defineExpose({ print: printRecord, printWhenReady });
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[320px] max-w-md flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-eye"></i> {{ label }} Voucher — {{ record?.invoice }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto bg-slate-100 p-5">
                    <div v-if="loading || !record" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
                    <div v-else class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" v-html="receiptHtml()"></div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3">
                    <button type="button" @click="close" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                    <button type="button" @click="printRecord" class="inline-flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">
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
