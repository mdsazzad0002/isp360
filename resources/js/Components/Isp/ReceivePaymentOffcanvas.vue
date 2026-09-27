<script setup>
import ReceivePaymentForm from './ReceivePaymentForm.vue';

// Bill collection in a side panel, stacked above another panel (e.g. the customer ledger).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    customer: { type: Object, default: () => ({}) },
    // preselects this invoice with its full due amount
    invoiceId: { type: [Number, null], default: null },
});
const emit = defineEmits(['update:modelValue', 'saved']);

function close() {
    emit('update:modelValue', false);
}
function onSaved(id) {
    emit('saved', id);
    close();
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-[60] flex justify-end">
            <div class="absolute inset-0 bg-slate-900/40" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in md:w-[62%] md:min-w-[320px]">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="min-w-0 truncate text-base font-bold text-slate-800">
                        <i class="bi bi-cash-coin"></i> Receive Payment — {{ customer?.name }}
                        <span class="text-sm font-normal text-slate-400">{{ customer?.code }}</span>
                    </h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <ReceivePaymentForm v-if="customer?.id" :key="`${customer.id}-${invoiceId}`" :customer-id="customer.id" :invoice-id="invoiceId" @saved="onSaved" />
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
