<script setup>
import { fmtMoney } from '../lib/isp';
import { ref, computed, watch, nextTick } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import JsBarcode from 'jsbarcode';
import { useToast } from '../lib/toast';
import { useCan } from '../lib/can';

const props = defineProps({
    product: { type: Object, required: true },
});

const toast = useToast();
const can = useCan();
const page = usePage();

const multiPriceEnabled = computed(() => page.props.company?.multi_price_status === 'active');
const expireEnabled = computed(() => page.props.company?.expire_status === 'active');
const showBatchOptions = computed(() => multiPriceEnabled.value || expireEnabled.value);

const code = ref(props.product.code);
const name = ref(props.product.name);
const price = ref(Number(props.product.sale_rate ?? 0).toFixed(2));
const quantity = ref('');
const isSingle = ref(true);
const xAxis = ref(1.5);
const yAxis = ref(1);

const onProgress = ref(false);
const isLoading = ref(null);
const products = ref([]);
const batchOptions = ref([]);

function formatExpDate(expDate) {
    return expDate || 'No Expire';
}

async function loadBatchOptions() {
    batchOptions.value = [];
    if (!showBatchOptions.value || !props.product.id) return;

    const res = await axios.get(`/product/${props.product.id}/batch-prices`);
    batchOptions.value = res.data.map((row) => ({
        code: multiPriceEnabled.value ? `${props.product.code}P${row.purchase_id}` : `${props.product.code}B${row.id}`,
        label: `Exp: ${formatExpDate(row.exp_date)} — Qty: ${row.quantity}`,
        sale_rate: row.sale_rate,
    }));
}

watch(
    () => props.product,
    (product) => {
        code.value = product.code;
        name.value = product.name;
        price.value = Number(product.sale_rate ?? 0).toFixed(2);
        quantity.value = '';
        products.value = [];
        isLoading.value = null;
        loadBatchOptions();
    }
);

// When Multi Price is on, each batch has its own sale price — reflect it here as the
// selected code changes so the printed price matches what POS will actually charge.
watch(code, (selectedCode) => {
    if (!multiPriceEnabled.value) return;
    if (selectedCode === props.product.code) {
        price.value = Number(props.product.sale_rate ?? 0).toFixed(2);
        return;
    }
    const option = batchOptions.value.find((o) => o.code === selectedCode);
    if (option) {
        price.value = Number(option.sale_rate ?? 0).toFixed(2);
    }
});

loadBatchOptions();

async function barcodeGenerate() {
    products.value = [];
    onProgress.value = true;
    isLoading.value = false;
    await new Promise((resolve) => setTimeout(resolve, 300));

    if (quantity.value === '' || quantity.value == null) {
        quantity.value = 1;
    }

    const product = { code: code.value, name: name.value, sale_rate: parseFloat(price.value).toFixed(2) };
    for (let i = 0; i < quantity.value; i++) {
        products.value.push(product);
    }

    onProgress.value = false;
    isLoading.value = true;

    await nextTick();
    const elements = document.querySelectorAll(isSingle.value ? '.singlebarcode' : '.barcode');
    products.value.forEach((item, index) => {
        if (elements[index]) {
            JsBarcode(elements[index], item.code, { format: 'CODE128', width: 1, height: isSingle.value ? 40 : 25, fontSize: 12, margin: 0, displayValue: false });
        }
    });
}

function print() {
    const oldTitle = document.title;
    document.title = 'Barcode Generate';
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = 'none';
    document.body.appendChild(iframe);
    iframe.srcdoc = `
        <html>
        <head>
            <style>
                @page { margin: 0; }
                * { box-sizing: border-box; }
                html, body { margin: 0; padding: 0; }
                body { display: flex; flex-wrap: wrap; }
            </style>
        </head>
        <body>${document.querySelector('.output').innerHTML}</body>
        </html>
    `;
    iframe.onload = async () => {
        iframe.contentWindow.focus();
        await new Promise((resolve) => setTimeout(resolve, 400));
        iframe.contentWindow.print();
        document.body.removeChild(iframe);
        document.title = oldTitle;
    };
}
</script>

<template>
    <div>
        <form @submit.prevent="barcodeGenerate" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
                            Product Code
                            <span v-if="batchOptions.length > 0" class="rounded-full bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700">
                                +{{ batchOptions.length }} batch{{ batchOptions.length > 1 ? 'es' : '' }}
                            </span>
                        </label>
                        <select v-if="batchOptions.length > 0" v-model="code" class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm">
                            <option :value="product.code">{{ product.code }} (Default)</option>
                            <option v-for="option in batchOptions" :key="option.code" :value="option.code">{{ option.code }} — {{ option.label }}</option>
                        </select>
                        <input v-else type="text" v-model="code" readonly class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Sale Rate</label>
                        <input type="number" min="0" step="any" v-model="price" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Quantity (per code)</label>
                        <input type="number" min="0" step="1" v-model="quantity" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Product Name</label>
                        <input type="text" v-model="name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div v-if="isSingle" class="flex items-end gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">X (in)</label>
                            <input type="number" step="0.01" min="0" v-model="xAxis" class="w-24 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Y (in)</label>
                            <input type="number" step="0.01" min="0" v-model="yAxis" class="w-24 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" v-model="isSingle" @change="products = []" />
                            Single Barcode
                        </label>
                        <button v-if="can('entry')" :disabled="onProgress" type="submit" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                            Generate
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div v-if="isLoading" class="mt-3">
            <div v-if="products.length > 0" class="text-right">
                <button type="button" @click="print" class="text-sm text-brand-600 hover:underline"><i class="bi bi-printer"></i> Print</button>
            </div>
            <div class="output mt-2 flex flex-wrap justify-center gap-0 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <template v-if="!isSingle">
                    <div v-for="(item, sl) in products" :key="sl" style="padding:3px; float:left; height:95px; width:131px; border:1px solid #ddd;">
                        <div style="width:131px; text-align:center; float:right;">
                            <p style="font-size:10px;margin:0 0 2px 1px;padding:2px 0 0 0;font-weight:bolder;text-align:center;line-height:1;">{{ item.name }}</p>
                            <img class="barcode" style="line-height:0;" />
                            <p style="margin:0;font-size:12px;margin-top:-3px;text-align:center;font-weight:900;">{{ item.code }}</p>
                            <p style="margin:0;margin-top:-1px;text-align:center;font-size:12px;font-weight:bolder;">{{ fmtMoney(item.sale_rate) }}</p>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div
                        v-for="(item, sl) in products"
                        :key="sl"
                        :style="{ width: xAxis + 'in', height: yAxis + 'in', float: 'left', margin: 0, padding: 0, overflow: 'hidden', border: '1px solid #ccc', boxSizing: 'border-box', borderBottom: 'none' }"
                    >
                        <div :style="{ width: xAxis + 'in', height: yAxis + 'in', textAlign: 'center', margin: 0, padding: 0 }">
                            <p style="font-size:10px;margin:0 0 2px 1px;padding:2px 0 0 0;font-weight:bolder;text-align:center;line-height:1;">{{ item.name }}</p>
                            <img class="singlebarcode" style="line-height:0;" />
                            <p style="margin:0;font-size:12px;margin-top:-3px;text-align:center;font-weight:900;">{{ item.code }}</p>
                            <p style="margin:0;margin-top:-1px;text-align:center;font-size:12px;font-weight:bolder;">{{ fmtMoney(item.sale_rate) }}</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
