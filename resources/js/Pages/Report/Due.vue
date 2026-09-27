<script setup>
import { ref, computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import * as XLSX from 'xlsx';
import SearchSelect from '../../Components/SearchSelect.vue';
import Pagination from '../../Components/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import LedgerOffcanvas from './LedgerOffcanvas.vue';
import PaymentOffcanvas from '../../Components/PaymentOffcanvas.vue';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const props = defineProps({
    mode: { type: String, required: true }, // 'customer' | 'supplier'
    dues: { type: Object, required: true },
    filters: {
        type: Object,
        default: () => ({ search: '', date: '', sortBy: 'name', sortDir: 'asc', perPage: 20 }),
    },
});

const page = usePage();
const title = props.mode === 'customer' ? 'Customer Due' : 'Supplier Due';
const listUrl = `/get-${props.mode}`;
const dueUrl = `/${props.mode}Due`;
const entityIdParam = `${props.mode}Id`;
const collectLabel = props.mode === 'customer' ? 'Collect' : 'Pay';

function actionLabel(item) {
    if (props.mode === 'customer' && parseFloat(item.due) < 0) {
        return 'Pay';
    }
    if (props.mode === 'supplier' && parseFloat(item.due) < 0) {
        return 'Collect';
    }
    return collectLabel;
}

const showPaymentOffcanvas = ref(false);
const paymentEntity = ref({});

function openPayment(item) {
    paymentEntity.value = { id: item.id, code: item.code, name: item.name, phone: item.phone };
    showPaymentOffcanvas.value = true;
}

function onPaid() {
    load(currentPage.value);
}

const rows = computed(() =>
    props.dues.data.map((item, index) => ({
        ...item,
        sl: (props.dues.current_page - 1) * props.dues.per_page + index + 1,
    }))
);
const currentPage = computed(() => props.dues.current_page);
const totalPages = computed(() => props.dues.last_page);

const options = ref([]);
const selected = ref(null);
const searchType = ref(props.filters[entityIdParam] ? props.mode : '');

const date = ref(props.filters.date || '');
const dueStatus = ref(props.filters.dueStatus || '');
const search = ref(props.filters.search || '');
const sortBy = ref(props.filters.sortBy || 'name');
const sortDir = ref(props.filters.sortDir || 'asc');
const perPage = ref(props.filters.perPage || 20);
const perPageOptions = [20, 50, 100, 200, 500];
let filterTimeout = null;

function getOptions() {
    axios.post(listUrl).then((res) => {
        options.value = res.data;
        if (props.filters[entityIdParam]) {
            selected.value = options.value.find((item) => item.id == props.filters[entityIdParam]) ?? null;
        }
    });
}

function onChangeSearchType() {
    selected.value = null;
    if (searchType.value === props.mode) {
        getOptions();
    } else {
        load(1);
    }
}

function currentFilters(pageNumber) {
    return {
        page: pageNumber,
        [entityIdParam]: searchType.value === props.mode && selected.value ? selected.value.id : '',
        date: date.value,
        dueStatus: searchType.value === props.mode ? '' : dueStatus.value,
        search: search.value,
        sortBy: sortBy.value,
        sortDir: sortDir.value,
        perPage: perPage.value,
    };
}

function load(pageNumber = 1) {
    router.get(dueUrl, currentFilters(pageNumber), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['dues', 'filters'],
    });
}

function onFilterInput() {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => load(1), 300);
}

function onPerPageChange() {
    load(1);
}

function changePage(p) {
    load(p);
}

function sortColumn(column) {
    if (sortBy.value === column) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = column;
        sortDir.value = 'asc';
    }
    load(1);
}

function grandTotal() {
    return rows.value.reduce((pre, cur) => pre + parseFloat(cur.due), 0).toFixed(2);
}

function print() {
    let entityText = '';
    if (selected.value) {
        entityText = `
            <p><strong>${props.mode === 'customer' ? 'Customer' : 'Supplier'} ID:</strong> ${selected.value.code}</p>
            <p><strong>Name:</strong> ${selected.value.name}</p>
            <p><strong>Mobile:</strong> ${selected.value.phone}</p>`;
    }

    const rowsHtml = rows.value.length
        ? rows.value
              .map(
                  (item) => `
                <tr>
                    <td>${item.sl}</td>
                    <td>${item.code ?? ''}</td>
                    <td>${item.name ?? ''}</td>
                    <td>${item.phone ?? ''}</td>
                    <td>${item.address ?? ''}</td>
                    <td style="text-align:right;">${parseFloat(item.due).toFixed(2)}</td>
                </tr>`
              )
              .join('') + `<tr><td colspan="5" style="text-align:center;font-weight:bold;">Total (this page)</td><td style="text-align:right;font-weight:bold;">${grandTotal()}</td></tr>`
        : '<tr><td colspan="6" style="text-align:center;">Not Found Data</td></tr>';

    const bodyHtml = `
        ${entityText}
        <table>
            <thead><tr><th>Sl</th><th>Code</th><th>Name</th><th>Mobile</th><th>Address</th><th>Due</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument(title, bodyHtml, page.props.company);
}

const showLedgerOffcanvas = ref(false);
const ledgerEntity = ref({});

function openLedger(item) {
    ledgerEntity.value = { id: item.id, code: item.code, name: item.name, phone: item.phone };
    showLedgerOffcanvas.value = true;
}

function exportExcel() {
    const params = new URLSearchParams();
    const f = currentFilters(1);
    Object.entries(f).forEach(([key, value]) => {
        if (key !== 'page' && value !== '' && value != null) params.append(key, value);
    });
    window.location.href = `/${props.mode}Due/export-excel?${params.toString()}`;
}
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2 bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2.5">
                <i class="bi bi-funnel text-white/90"></i>
                <h3 class="text-sm font-semibold text-white">{{ title }} Filter</h3>
            </div>
            <div class="flex flex-wrap items-end gap-4 p-4">
                <div class="w-44">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Search Type</label>
                    <select v-model="searchType" @change="onChangeSearchType" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400">
                        <option value="">All</option>
                        <option :value="mode">By {{ mode === 'customer' ? 'Customer' : 'Supplier' }}</option>
                    </select>
                </div>
                <div v-if="searchType === mode" class="w-64">
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ mode === 'customer' ? 'Customer' : 'Supplier' }}</label>
                    <SearchSelect :options="options" v-model="selected" label="display_name" :placeholder="`Select ${mode}`" @update:model-value="load(1)" />
                </div>
                <div v-if="searchType !== mode && mode === 'customer'" class="w-40">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Due status</label>
                    <select v-model="dueStatus" @change="load(1)" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400">
                        <option value="">All customers</option>
                        <option value="due">Has due</option>
                        <option value="advance">Has advance</option>
                        <option value="nonzero">Due or advance</option>
                        <option value="clear">Clear (0)</option>
                    </select>
                </div>
                <div class="w-44">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                    <input type="date" v-model="date" @change="load(1)" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400" />
                </div>
                <div class="h-8 w-px bg-slate-200"></div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Quick Search</label>
                    <input type="text" v-model="search" @input="onFilterInput" placeholder="Name, code, mobile..." class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Show</label>
                    <select v-model.number="perPage" @change="onPerPageChange" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400">
                        <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                </div>
                <div class="ml-auto flex items-center gap-3">
                    <button type="button" @click="exportExcel" title="Export Excel" class="text-slate-500 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel text-lg"></i>
                    </button>
                    <button type="button" @click="print" title="Print current page" class="text-slate-500 hover:text-brand-500">
                        <i class="bi bi-printer text-lg"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div v-if="dues.total_due !== undefined" class="mb-3 flex flex-wrap gap-4 text-sm">
                <span class="text-slate-500">{{ dues.total }} {{ mode === 'customer' ? 'customers' : 'suppliers' }}</span>
                <span class="text-slate-500">Total due: <strong class="text-slate-800">{{ Number(dues.total_due).toFixed(2) }}</strong></span>
                <span class="text-slate-500">Total advance: <strong class="text-rose-600">{{ Number(dues.total_advance).toFixed(2) }}</strong></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border border-slate-200 text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="border-r border-slate-200 px-3 py-2 font-medium">Sl</th>
                            <th class="border-r border-slate-200 px-3 py-2 font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('code')">
                                Code <i :class="sortBy === 'code' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="border-r border-slate-200 px-3 py-2 font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('name')">
                                Name <i :class="sortBy === 'name' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="border-r border-slate-200 px-3 py-2 font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('phone')">
                                Mobile <i :class="sortBy === 'phone' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="border-r border-slate-200 px-3 py-2 font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('address')">
                                Address <i :class="sortBy === 'address' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="border-r border-slate-200 px-3 py-2 text-right font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('due')">
                                Due <i :class="sortBy === 'due' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="px-3 py-2 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in rows" :key="item.id" class="border-b border-slate-100">
                            <td class="border-r border-slate-200 px-3 py-2">{{ item.sl }}</td>
                            <td class="border-r border-slate-200 px-3 py-2">{{ item.code }}</td>
                            <td class="border-r border-slate-200 px-3 py-2">
                                <button type="button" @click="openLedger(item)" class="text-brand-600 hover:underline">{{ item.name }}</button>
                            </td>
                            <td class="border-r border-slate-200 px-3 py-2">{{ item.phone }}</td>
                            <td class="border-r border-slate-200 px-3 py-2">{{ item.address }}</td>
                            <td class="border-r border-slate-200 px-3 py-2 text-right" :class="{ 'text-rose-600': parseFloat(item.due) < 0 }">{{ parseFloat(item.due).toFixed(2) }}</td>
                            <td class="px-3 py-2">
                                <button type="button" @click="openPayment(item)" class="inline-flex items-center gap-1 rounded-md bg-brand-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-600">
                                    <i class="bi bi-cash-coin"></i> {{ actionLabel(item) }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="rows.length > 0" class="bg-slate-50 font-semibold">
                            <td colspan="5" class="border-r border-slate-200 px-3 py-2 text-right">Total (this page)</td>
                            <td class="border-r border-slate-200 px-3 py-2 text-right">{{ grandTotal() }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">Not Found Data</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="currentPage" :total-pages="totalPages" @change="changePage" />
        </div>

        <LedgerOffcanvas v-model="showLedgerOffcanvas" :mode="mode" :entity="ledgerEntity" />
        <PaymentOffcanvas v-model="showPaymentOffcanvas" :mode="mode" :entity="paymentEntity" @paid="onPaid" />
    </div>
</template>
