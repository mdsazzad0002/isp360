<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    mode: { type: String, required: true }, // 'customer' | 'supplier'
    entity: { type: Object, default: () => ({}) }, // { id, code, name, phone }
});

const emit = defineEmits(['update:modelValue']);

const modeLabel = props.mode === 'supplier' ? 'Supplier' : 'Customer';
const columnLabels = { bill: 'Bill', paid: 'Inv.Paid', due: 'Inv.Due', cash_payment: 'Payment', cash_receive: 'Receive', return_amount: 'Returned', balance: 'Balance' };

const dateFrom = ref('');
const dateTo = ref('');
const ledgers = ref([]);
const previousBalance = ref(0);
const loading = ref(false);

function close() {
    emit('update:modelValue', false);
}

function load() {
    if (!props.entity?.id) return;
    loading.value = true;
    axios
        .post(`/get-${props.mode}-ledger`, {
            [`${props.mode}Id`]: props.entity.id,
            dateFrom: dateFrom.value,
            dateTo: dateTo.value,
        })
        .then((res) => {
            ledgers.value = res.data.ledgers;
            previousBalance.value = res.data.previousBalance;
        })
        .finally(() => {
            loading.value = false;
        });
}

watch(
    () => [props.modelValue, props.entity?.id],
    ([open, id]) => {
        if (open && id) load();
    },
    { immediate: true }
);

function sumField(field) {
    return ledgers.value.reduce((pre, cur) => pre + parseFloat(cur[field]), 0).toFixed(2);
}

function lastBalance() {
    return ledgers.value.length ? parseFloat(ledgers.value[ledgers.value.length - 1].balance).toFixed(2) : '0.00';
}

function isClickableLedgerRow() {
    return false;
}

function openInvoice() {
    // No drill-down invoice types survive in this build.
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[85%] min-w-[320px] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-journal-text"></i> {{ modeLabel }} Ledger — {{ entity.name }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto bg-slate-100 p-5">
                    <div class="mb-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="flex flex-wrap items-end gap-3">
                            <p class="text-sm text-slate-600"><strong>Code:</strong> {{ entity.code }}</p>
                            <p v-if="entity.phone" class="text-sm text-slate-600"><strong>Mobile:</strong> {{ entity.phone }}</p>
                            <div class="ml-auto flex items-end gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                                    <input type="date" v-model="dateFrom" @change="load" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                                    <input type="date" v-model="dateTo" @change="load" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <div v-if="loading" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full border border-collapse border-slate-200 text-sm">
                                <thead>
                                    <tr class="bg-slate-50 text-left text-slate-600">
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Date</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">Description</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.bill }}</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.paid }}</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.due }}</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.cash_payment }}</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.cash_receive }}</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.return_amount }}</th>
                                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.balance }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="border border-slate-200 px-2 py-1.5"></td>
                                        <td colspan="7" class="border border-slate-200 px-2 py-1.5">Previous Balance</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ previousBalance }}</td>
                                    </tr>
                                    <tr
                                        v-for="(item, index) in ledgers"
                                        :key="index"
                                        :class="isClickableLedgerRow(item) ? 'cursor-pointer hover:bg-brand-50/40' : ''"
                                        @click="openInvoice(item)"
                                    >
                                        <td class="border border-slate-200 px-2 py-1.5">{{ item.date }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5">
                                            <span :class="isClickableLedgerRow(item) ? 'text-brand-600 underline decoration-dotted' : ''">{{ item.description }}</span>
                                        </td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ item.bill }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ item.paid }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ item.due }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ item.cash_payment }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ item.cash_receive }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right">{{ item.return_amount }}</td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right font-medium">{{ parseFloat(item.balance).toFixed(2) }}</td>
                                    </tr>
                                    <tr v-if="ledgers.length > 0" class="bg-slate-50 font-semibold">
                                        <td colspan="2" class="border border-slate-200 px-2 py-2 text-center">Total</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ sumField('bill') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ sumField('paid') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ sumField('due') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ sumField('cash_payment') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ sumField('cash_receive') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ sumField('return_amount') }}</td>
                                        <td class="border border-slate-200 px-2 py-2 text-right">{{ lastBalance() }}</td>
                                    </tr>
                                    <tr v-if="!loading && ledgers.length === 0">
                                        <td colspan="9" class="px-3 py-6 text-center text-slate-400">No transactions found</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
