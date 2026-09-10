<script setup>
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useToast } from '../lib/toast';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    product: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'updated']);

const page = usePage();
const multiPriceEnabled = computed(() => page.props.company?.multi_price_status === 'active');

const toast = useToast();
const loading = ref(false);
const batches = ref([]);
const savingId = ref(null);

const editingSaleRate = ref(0);
const savingSaleRate = ref(false);

function close() {
    emit('update:modelValue', false);
}

async function load() {
    if (!props.product) return;

    if (!multiPriceEnabled.value) {
        loading.value = true;
        try {
            const res = await axios.post('/get-product', { productId: props.product.id });
            editingSaleRate.value = res.data?.[0]?.sale_rate ?? props.product.sale_rate ?? 0;
        } finally {
            loading.value = false;
        }
        return;
    }

    loading.value = true;
    try {
        const res = await axios.get(`/product/${props.product.id}/batch-prices`);
        batches.value = res.data.map((row) => ({ ...row, editing_sale_rate: row.sale_rate }));
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) load();
    },
);

async function saveBatch(row) {
    savingId.value = row.id;
    try {
        await axios.post(`/product/batch-prices/${row.id}`, { sale_rate: row.editing_sale_rate });
        row.sale_rate = row.editing_sale_rate;
        toast.success('Batch sale price updated');
        emit('updated', row);
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to update batch price');
    } finally {
        savingId.value = null;
    }
}

async function saveSaleRate() {
    savingSaleRate.value = true;
    try {
        await axios.post(`/product/${props.product.id}/sale-rate`, { sale_rate: editingSaleRate.value });
        toast.success('Sale price updated');
        emit('updated', { sale_rate: editingSaleRate.value });
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to update sale price');
    } finally {
        savingSaleRate.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue && product" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70vw] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-cash-stack"></i> Manage Sale Price — {{ product.name }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <template v-if="multiPriceEnabled">
                        <p class="mb-4 text-xs text-slate-500">
                            Each row is a purchase batch of this product. Editing a batch's sale price also updates the product's default sale
                            price. Scanning that batch's barcode (product code + purchase id) in POS charges this price; otherwise POS uses the
                            oldest available batch automatically.
                        </p>

                        <div v-if="loading" class="py-10 text-center text-sm text-slate-500">Loading...</div>
                        <div v-else-if="batches.length === 0" class="py-10 text-center text-sm text-slate-500">No purchase batches found for this product.</div>

                        <div v-else class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-100 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2">Batch Code</th>
                                        <th class="px-3 py-2">Exp Date</th>
                                        <th class="px-3 py-2 text-right">Purchased Qty</th>
                                        <th class="px-3 py-2 text-right">Remaining Stock</th>
                                        <th class="px-3 py-2 text-right">Purchase Price</th>
                                        <th class="px-3 py-2 text-right">Sale Price</th>
                                        <th class="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in batches" :key="row.id" class="border-t border-slate-100">
                                        <td class="px-3 py-2 font-mono text-xs text-slate-500">{{ product.code }}P{{ row.purchase_id }}</td>
                                        <td class="px-3 py-2">{{ row.exp_date || '-' }}</td>
                                        <td class="px-3 py-2 text-right">{{ row.quantity }}</td>
                                        <td class="px-3 py-2 text-right">{{ row.remaining_stock }}</td>
                                        <td class="px-3 py-2 text-right text-slate-500">{{ row.purchase_rate }}</td>
                                        <td class="px-3 py-2 text-right">
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                v-model="row.editing_sale_rate"
                                                class="w-24 rounded-md border border-slate-300 px-2 py-1 text-right text-sm"
                                            />
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button
                                                type="button"
                                                @click="saveBatch(row)"
                                                :disabled="savingId === row.id"
                                                class="rounded-md bg-brand-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                            >
                                                {{ savingId === row.id ? 'Saving...' : 'Save' }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <template v-else>
                        <p class="mb-4 text-xs text-slate-500">
                            Multi Sale Price is currently disabled, so this product uses a single sale price everywhere. Enable it in Sale
                            Settings to manage prices per purchase batch instead.
                        </p>

                        <div v-if="loading" class="py-10 text-center text-sm text-slate-500">Loading...</div>
                        <div v-else class="max-w-xs rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Sale Price</label>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    v-model="editingSaleRate"
                                    class="w-32 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                />
                                <button
                                    type="button"
                                    @click="saveSaleRate"
                                    :disabled="savingSaleRate"
                                    class="rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    {{ savingSaleRate ? 'Saving...' : 'Save' }}
                                </button>
                            </div>
                        </div>
                    </template>
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
