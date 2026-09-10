<script setup>
import BarcodeGenerator from './BarcodeGenerator.vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    product: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue']);

function close() {
    emit('update:modelValue', false);
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue && product" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70vw] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-upc-scan"></i> Generate Barcode — {{ product.name }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <BarcodeGenerator :product="product" />
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
