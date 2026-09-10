<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import QuickLedgerBody from './QuickLedgerBody.vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    mode: { type: String, required: true }, // 'customer' | 'supplier' | 'provider' | 'employee'
    partyId: { type: [String, Number], default: null },
    partyName: { type: String, default: '' },
    inline: { type: Boolean, default: false }, // render as a static panel instead of a slide-over offcanvas
});

const emit = defineEmits(['update:modelValue']);

function toDateStr(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function todayStr() {
    return toDateStr(new Date());
}

function daysAgoStr(days) {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return toDateStr(d);
}

function monthsAgoStr(months) {
    const d = new Date();
    d.setMonth(d.getMonth() - months);
    return toDateStr(d);
}

const label = computed(() => ({ customer: 'Customer', provider: 'Provider', employee: 'Employee' }[props.mode] || 'Supplier'));
const ledgerUrl = computed(() => `/get-${props.mode}-ledger`);

const defaultColumnLabels = { bill: 'Bill', paid: 'Paid', due: 'Due', cash_payment: 'Payment', cash_receive: 'Receive', return_amount: 'Returned', balance: 'Balance' };
const employeeColumnLabels = { bill: 'Salary', paid: 'Paid', due: 'Due', cash_payment: 'Payment', cash_receive: 'Advance', return_amount: 'Returned', balance: 'Payable' };
const columnLabels = computed(() => (props.mode === 'employee' ? employeeColumnLabels : defaultColumnLabels));

function balanceClass(value) {
    if (props.mode !== 'employee') return '';
    return parseFloat(value) >= 0 ? 'text-emerald-600' : 'text-red-600';
}

const quickRanges = [
    { key: '15d', label: '15D', from: () => daysAgoStr(15) },
    { key: '1m', label: '1M', from: () => monthsAgoStr(1) },
    { key: '2m', label: '2M', from: () => monthsAgoStr(2) },
];
const activeRange = ref('15d');

const dateFrom = ref(daysAgoStr(15));
const dateTo = ref(todayStr());
const ledgers = ref([]);
const previousBalance = ref(0);
const loading = ref(false);
const loaded = ref(false);

function close() {
    emit('update:modelValue', false);
}

function applyQuickRange(range) {
    activeRange.value = range.key;
    dateFrom.value = range.from();
    dateTo.value = todayStr();
    showLedger();
}

function sumField(field) {
    return ledgers.value.reduce((pre, cur) => pre + parseFloat(cur[field] || 0), 0).toFixed(2);
}

function lastBalance() {
    return ledgers.value.length ? parseFloat(ledgers.value[ledgers.value.length - 1].balance).toFixed(2) : parseFloat(previousBalance.value || 0).toFixed(2);
}

function onDateInput() {
    activeRange.value = null;
}

async function showLedger() {
    if (!props.partyId) return;
    loading.value = true;
    try {
        const res = await axios.post(ledgerUrl.value, {
            [`${props.mode}Id`]: props.partyId,
            dateFrom: dateFrom.value,
            dateTo: dateTo.value,
        });
        ledgers.value = res.data.ledgers;
        previousBalance.value = res.data.previousBalance;
        loaded.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.modelValue, props.partyId],
    ([open, id]) => {
        if ((props.inline || open) && id) showLedger();
    },
    { immediate: true }
);
</script>

<template>
    <div v-if="inline">
        <div v-if="!partyId" class="rounded-lg border border-dashed border-slate-300 bg-white p-4 text-center text-sm text-slate-400">
            Select {{ label.toLowerCase() }} to view ledger
        </div>
        <template v-else>
            <h3 class="mb-2 text-sm font-semibold text-slate-700"><i class="bi bi-journal-text"></i> {{ label }} Ledger — {{ partyName }}</h3>
            <QuickLedgerBody
                :loading="loading"
                :loaded="loaded"
                :ledgers="ledgers"
                :previous-balance="previousBalance"
                :column-labels="columnLabels"
                :balance-class="balanceClass"
                :sum-field="sumField"
                :last-balance="lastBalance"
                :date-from="dateFrom"
                :date-to="dateTo"
                :quick-ranges="quickRanges"
                :active-range="activeRange"
                @update:date-from="(v) => (dateFrom = v)"
                @update:date-to="(v) => (dateTo = v)"
                @date-input="onDateInput"
                @quick-range="applyQuickRange"
                @show="showLedger"
            />
        </template>
    </div>
    <Teleport v-else to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[90%] min-w-[320px] max-w-3xl flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-journal-text"></i> {{ label }} Ledger — {{ partyName }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <QuickLedgerBody
                        :loading="loading"
                        :loaded="loaded"
                        :ledgers="ledgers"
                        :previous-balance="previousBalance"
                        :column-labels="columnLabels"
                        :balance-class="balanceClass"
                        :sum-field="sumField"
                        :last-balance="lastBalance"
                        :date-from="dateFrom"
                        :date-to="dateTo"
                        :quick-ranges="quickRanges"
                        :active-range="activeRange"
                        @update:date-from="(v) => (dateFrom = v)"
                        @update:date-to="(v) => (dateTo = v)"
                        @date-input="onDateInput"
                        @quick-range="applyQuickRange"
                        @show="showLedger"
                    />
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
