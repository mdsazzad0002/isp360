<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import LedgerStatement from '../../../Components/Isp/LedgerStatement.vue';
import ReceivePaymentOffcanvas from '../../../Components/Isp/ReceivePaymentOffcanvas.vue';
import { money } from '../../../lib/isp';

// ISP billing ledger (invoices, payments, notes, refunds) of one customer in a side panel,
// opened by clicking the customer's name in the list.
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    customer: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue', 'changed']);

const statementRef = ref(null);
const showReceive = ref(false);
const balance = ref(null);

watch(
    () => [props.modelValue, props.customer?.id],
    () => (balance.value = props.customer?.ledger_balance ?? null),
    { immediate: true },
);

function close() {
    showReceive.value = false;
    emit('update:modelValue', false);
}

// After a payment: refresh the statement and the due shown in the header, and let the list reload.
async function onPaid() {
    statementRef.value?.load();
    const res = await axios.post('/isp/get-customer-dues', { customerId: props.customer.id });
    balance.value = res.data.balance;
    emit('changed');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in md:w-[70%] md:min-w-[320px]">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 bg-white px-6 py-4">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-bold text-slate-800"><i class="bi bi-journal-text"></i> Customer Ledger — {{ customer?.name }}</h2>
                        <div class="text-xs text-slate-500">
                            {{ customer?.code }} · {{ customer?.phone }}
                            <span v-if="balance !== null">
                                ·
                                <span v-if="Number(balance) > 0" class="font-medium text-red-600">Due {{ money(balance) }}</span>
                                <span v-else-if="Number(balance) < 0" class="font-medium text-emerald-700">Advance {{ money(-balance) }}</span>
                                <span v-else>No due</span>
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <button type="button" class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs text-white hover:bg-emerald-700" @click="showReceive = true"><i class="bi bi-cash-coin"></i> Receive payment</button>
                        <Link :href="`/isp/customer/${customer?.id}`" class="text-xs text-brand-600 hover:underline"><i class="bi bi-person-lines-fill"></i> Customer 360</Link>
                        <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <LedgerStatement v-if="customer?.id" ref="statementRef" :key="customer.id" :customer="customer" />
                    </div>
                </div>
            </div>
        </div>
        <ReceivePaymentOffcanvas v-model="showReceive" :customer="customer" @saved="onPaid" />
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
