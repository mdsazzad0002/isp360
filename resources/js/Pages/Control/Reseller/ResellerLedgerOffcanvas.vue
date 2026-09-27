<script setup>
import ResellerStatement from '../../../Components/Isp/ResellerStatement.vue';

// Reseller ledger in a side panel, opened from the reseller list (same pattern as the customer ledger).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    reseller: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

function close() {
    emit('update:modelValue', false);
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end" @keydown.esc="close">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in md:w-[75%] md:min-w-[320px]">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="min-w-0 truncate text-base font-bold text-slate-800">
                        <i class="bi bi-journal-text"></i> Reseller Ledger — {{ reseller?.name }}
                        <span class="text-sm font-normal text-slate-400">{{ reseller?.code }}</span>
                    </h2>
                    <div class="flex shrink-0 items-center gap-3">
                        <a :href="`/isp/reseller-ledger?resellerId=${reseller?.id}`" target="_blank" class="text-xs text-brand-600 hover:underline"><i class="bi bi-box-arrow-up-right"></i> Full page</a>
                        <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    <ResellerStatement v-if="reseller?.id" endpoint="/isp/get-reseller-ledger" :reseller-id="reseller.id" require-reseller />
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
