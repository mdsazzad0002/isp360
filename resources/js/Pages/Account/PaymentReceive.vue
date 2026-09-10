<script setup>
import { reactive, ref, computed, onMounted, nextTick } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import SearchSelect from '../../Components/SearchSelect.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import ReceiveQuickView from './ReceiveQuickView.vue';
import QuickAddParty from './QuickAddParty.vue';
import QuickLedger from './QuickLedger.vue';
import { useToast } from '../../lib/toast';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const props = defineProps({
    mode: { type: String, required: true }, // 'payment' | 'receive'
    role: { type: String, default: '' },
    customerId: { type: [String, Number], default: null },
    supplierId: { type: [String, Number], default: null },
    providerId: { type: [String, Number], default: null },
    employeeId: { type: [String, Number], default: null },
});

const toast = useToast();
const page = usePage();
const activeMode = ref(props.mode);
const title = computed(() => (activeMode.value === 'payment' ? 'Payment Amount' : 'Receive Amount'));
const listUrl = computed(() => `/get-${activeMode.value}`);
const saveUrl = computed(() => `/${activeMode.value}`);
const updateUrl = computed(() => `/update-${activeMode.value}`);
const deleteUrl = computed(() => `/delete-${activeMode.value}`);
const invoiceUrl = computed(() => `/get-${activeMode.value}-invoice`);

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function daysAgoStr(days) {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const RANGE_SHORTCUTS = [
    { label: '15D', days: 14 },
    { label: '1M', days: 29 },
    { label: '2M', days: 59 },
];
function applyRange(days) {
    dateFrom.value = daysAgoStr(days);
    dateTo.value = todayStr();
    load();
}
function isActiveRange(days) {
    return dateFrom.value === daysAgoStr(days) && dateTo.value === todayStr();
}

function emptyForm() {
    let type = activeMode.value === 'receive' ? 'customer' : 'supplier';
    if (props.customerId && activeMode.value === 'payment') type = 'customer';
    if (props.providerId) type = 'provider';
    if (props.employeeId && activeMode.value === 'payment') type = 'employee';
    return {
        id: '',
        invoice: '',
        date: todayStr(),
        type,
        payment_method: 'cash',
        amount: 0,
        previous_due: 0,
        note: '',
    };
}

const form = reactive(emptyForm());
const banks = ref([]);
const selectedBank = ref(null);
const customers = ref([]);
const selectedCustomer = ref(null);
const suppliers = ref([]);
const selectedSupplier = ref(null);
const providers = ref([]);
const selectedProvider = ref(null);
const employees = ref([]);
const selectedEmployee = ref(null);
const rows = ref([]);
const careflowDueOrders = ref([]);
const careflowPayAmount = reactive({});
const careflowPaying = ref(null);
const search = ref('');
const dateFrom = ref(daysAgoStr(14));
const dateTo = ref(todayStr());
const onProgress = ref(false);
const showQuickView = ref(false);
const quickViewRecordId = ref(null);
const showQuickAdd = ref(false);
const showQuickLedger = ref(false);
const quickViewRef = ref(null);

function openRecord(id) {
    quickViewRecordId.value = id;
    showQuickView.value = true;
}

const selectedParty = computed(() => {
    if (form.type === 'customer') return selectedCustomer.value;
    if (form.type === 'provider') return selectedProvider.value;
    if (form.type === 'employee') return selectedEmployee.value;
    return selectedSupplier.value;
});

async function onPartyCreated({ phone }) {
    if (form.type === 'customer') {
        await getCustomers();
        const found = customers.value.find((item) => item.phone === phone);
        if (found) await onChangeCustomer(found);
    } else if (form.type === 'supplier') {
        await getSuppliers();
        const found = suppliers.value.find((item) => item.phone === phone);
        if (found) await onChangeSupplier(found);
    }
}

const dateLocked = computed(() => !(props.role === 'Superadmin' || props.role === 'admin'));

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return rows.value;
    return rows.value.filter((row) => JSON.stringify(row).toLowerCase().includes(term));
});

const totalAmount = computed(() => filteredRows.value.reduce((pr, cu) => pr + parseFloat(cu.amount || 0), 0).toFixed(2));
const partyTypeLabel = computed(() => ({ customer: 'Customer', provider: 'Provider', employee: 'Employee' }[form.type] || 'Supplier'));

function getBanks() {
    axios.post('/get-bank').then((res) => {
        banks.value = res.data;
    });
}
function getCustomers() {
    return axios.post('/get-customer').then((res) => {
        customers.value = res.data;
    });
}
function getSuppliers() {
    return axios.post('/get-supplier').then((res) => {
        suppliers.value = res.data;
    });
}
function getProviders() {
    return axios.post('/get-careflow-provider').then((res) => {
        providers.value = res.data;
    });
}
function getEmployees() {
    return axios.post('/get-employee').then((res) => {
        employees.value = res.data;
    });
}

function partyLabel(item) {
    if (item.type === 'customer') return `${item.customer?.name} - ${item.customer?.code}`;
    if (item.type === 'provider') return `${item.provider?.name} - ${item.provider?.code}`;
    if (item.type === 'employee') return `${item.employee?.name} - ${item.employee?.emp_code}`;
    return `${item.supplier?.name} - ${item.supplier?.code}`;
}

function load() {
    axios.post(listUrl.value, { dateFrom: dateFrom.value, dateTo: dateTo.value, type: form.type }).then((res) => {
        rows.value = res.data.map((item) => ({
            ...item,
            name: partyLabel(item),
        }));
    });
}

function exportExcel() {
    const params = new URLSearchParams({ type: form.type, dateFrom: dateFrom.value, dateTo: dateTo.value });
    if (search.value !== '') params.append('search', search.value);
    window.location.href = `/${activeMode.value}/export-excel?${params.toString()}`;
}

function print() {
    const label = activeMode.value === 'payment' ? 'Payment' : 'Receive';
    const rowsHtml = filteredRows.value.length
        ? filteredRows.value
              .map(
                  (row, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>${row.invoice}</td>
                    <td>${row.date}</td>
                    <td>${row.name}</td>
                    <td>${row.payment_method}</td>
                    <td style="text-align:right;">${row.amount}</td>
                    <td>${row.note ?? ''}</td>
                </tr>`
              )
              .join('') + `<tr><th colspan="5" style="text-align:right;">Total</th><th style="text-align:right;">${totalAmount.value}</th><th></th></tr>`
        : '<tr><td colspan="7" style="text-align:center;">Not Found Data</td></tr>';

    const bodyHtml = `<table><thead><tr><th>Sl</th><th>Invoice</th><th>Date</th><th>${partyTypeLabel.value}</th><th>Method</th><th>Amount</th><th>Note</th></tr></thead><tbody>${rowsHtml}</tbody></table>`;
    printDocument(`${label} Record`, bodyHtml, page.props.company);
}

function fetchInvoice() {
    axios.post(invoiceUrl.value, { type: form.type }).then((res) => {
        form.invoice = res.data.invoice;
    });
}

function onChangeType() {
    form.previous_due = 0;
    selectedCustomer.value = null;
    selectedSupplier.value = null;
    selectedProvider.value = null;
    selectedEmployee.value = null;
    if (form.type === 'customer') {
        getCustomers();
    } else if (form.type === 'provider') {
        getProviders();
    } else if (form.type === 'employee') {
        getEmployees();
    } else {
        getSuppliers();
    }
    fetchInvoice();
    load();
}

function switchMode(newMode) {
    if (activeMode.value === newMode) return;
    activeMode.value = newMode;
    Object.assign(form, emptyForm());
    selectedBank.value = null;
    onChangeType();
}

function onChangeEmployee(val) {
    selectedEmployee.value = val;
    form.previous_due = 0;
}

async function onChangeSupplier(val) {
    selectedSupplier.value = val;
    if (val == null || val.id == '') return;
    const res = await axios.post('/get-supplierDue', { supplierId: val.id });
    form.previous_due = res.data[0].due;
}

async function onChangeProvider(val) {
    selectedProvider.value = val;
    if (val == null || val.id == '') return;
    const res = await axios.post('/get-careflow-provider-due', { providerId: val.id });
    form.previous_due = res.data[0]?.due ?? 0;
}

async function onChangeCustomer(val) {
    selectedCustomer.value = val;
    careflowDueOrders.value = [];
    if (val == null || val.id == '') return;
    const res = await axios.post('/get-customerDue', { customerId: val.id });
    form.previous_due = res.data[0].due;
    loadCareflowDueOrders();
}

function loadCareflowDueOrders() {
    if (!selectedCustomer.value?.id) return;
    axios.post('/get-careflow-order-due', { customer_id: selectedCustomer.value.id }).then((res) => {
        careflowDueOrders.value = res.data;
        res.data.forEach((o) => (careflowPayAmount[o.id] = o.due));
    });
}

async function payCareflowOrder(order) {
    const amount = parseFloat(careflowPayAmount[order.id]);
    if (!amount || amount <= 0) {
        toast.error('Enter a valid amount');
        return;
    }
    careflowPaying.value = order.id;
    try {
        const bank_id = form.payment_method === 'bank' && selectedBank.value ? selectedBank.value.id : null;
        const res = await axios.post('/careflow-order-payment', { id: order.id, amount, payment_method: form.payment_method, bank_id });
        toast.success(res.data.message);
        loadCareflowDueOrders();
    } catch (err) {
        toast.error(err.response?.data?.message || 'Something went wrong');
    } finally {
        careflowPaying.value = null;
    }
}

function resetForm() {
    const wasType = form.type;
    Object.assign(form, emptyForm());
    form.type = wasType;
    selectedBank.value = null;
    selectedCustomer.value = null;
    selectedSupplier.value = null;
    selectedProvider.value = null;
    onProgress.value = false;
}

async function saveData(printAfter = false) {
    if (!form.amount || parseFloat(form.amount) <= 0) {
        toast.error('Enter an amount greater than 0');
        return;
    }

    const url = form.id != '' ? updateUrl.value : saveUrl.value;
    const payload = { ...form };
    if (form.type === 'customer') payload.customer_id = selectedCustomer.value ? selectedCustomer.value.id : '';
    if (form.type === 'supplier') payload.supplier_id = selectedSupplier.value ? selectedSupplier.value.id : '';
    if (form.type === 'provider') payload.provider_id = selectedProvider.value ? selectedProvider.value.id : '';
    if (form.type === 'employee') payload.employee_id = selectedEmployee.value ? selectedEmployee.value.id : '';
    if (form.payment_method === 'bank') payload.bank_id = selectedBank.value ? selectedBank.value.id : '';

    onProgress.value = true;
    try {
        const res = await axios.post(url, payload);
        toast.success(res.data.message);
        resetForm();
        fetchInvoice();
        load();
        if (res.data.id) {
            openRecord(res.data.id);
            if (printAfter) {
                await nextTick();
                await quickViewRef.value?.printWhenReady();
            }
        }
    } catch (err) {
        onProgress.value = false;
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    }
}

function editRow(row) {
    Object.assign(form, {
        id: row.id,
        invoice: row.invoice,
        date: row.date,
        type: row.type,
        payment_method: row.payment_method,
        amount: row.amount,
        previous_due: row.previous_due,
        note: row.note,
    });
    selectedCustomer.value = customers.value.find((item) => item.id == row.customer_id) ?? null;
    selectedSupplier.value = suppliers.value.find((item) => item.id == row.supplier_id) ?? null;
    selectedProvider.value = providers.value.find((item) => item.id == row.provider_id) ?? null;
    selectedEmployee.value = employees.value.find((item) => item.id == row.employee_id) ?? null;
    selectedBank.value = banks.value.find((item) => item.id == row.bank_id) ?? null;
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post(deleteUrl.value, { id });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

onMounted(async () => {
    getBanks();
    fetchInvoice();
    load();

    const presetId = form.type === 'customer' ? props.customerId : form.type === 'provider' ? props.providerId : form.type === 'employee' ? props.employeeId : props.supplierId;
    if (presetId) {
        if (form.type === 'customer') {
            await getCustomers();
            const found = customers.value.find((item) => item.id == presetId);
            if (found) await onChangeCustomer(found);
        } else if (form.type === 'provider') {
            await getProviders();
            const found = providers.value.find((item) => item.id == presetId);
            if (found) await onChangeProvider(found);
        } else if (form.type === 'employee') {
            await getEmployees();
            const found = employees.value.find((item) => item.id == presetId);
            if (found) onChangeEmployee(found);
        } else {
            await getSuppliers();
            const found = suppliers.value.find((item) => item.id == presetId);
            if (found) await onChangeSupplier(found);
        }
    } else if (form.type === 'customer') {
        getCustomers();
    } else if (form.type === 'provider') {
        getProviders();
    } else if (form.type === 'employee') {
        getEmployees();
    } else {
        getSuppliers();
    }
});
</script>

<template>
    <div class="mx-auto space-y-3 p-4">
        <div class="flex gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
            <button
                type="button"
                @click="switchMode('receive')"
                class="flex-1 rounded-md py-2 text-sm font-semibold transition"
                :class="activeMode === 'receive' ? 'bg-emerald-500 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-50'"
            >
                <i class="bi bi-cash-coin"></i> Receive
            </button>
            <button
                type="button"
                @click="switchMode('payment')"
                class="flex-1 rounded-md py-2 text-sm font-semibold transition"
                :class="activeMode === 'payment' ? 'bg-red-500 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-50'"
            >
                <i class="bi bi-cash-stack"></i> Payment
            </button>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ title }}</h1>
            <form @submit.prevent="saveData()" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Invoice</label>
                        <input type="text" readonly :value="form.invoice" class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                        <select v-model="form.type" @change="onChangeType" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option :disabled="form.id != ''" value="customer">Customer</option>
                            <option :disabled="form.id != ''" value="supplier">Supplier</option>
                            <option :disabled="form.id != ''" value="provider">Provider (CareFlow)</option>
                            <option v-if="activeMode === 'payment'" :disabled="form.id != ''" value="employee">Employee</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                        <select v-model="form.payment_method" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank</option>
                        </select>
                    </div>
                    <div v-if="form.payment_method === 'bank'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Account</label>
                        <SearchSelect :options="banks" v-model="selectedBank" label="display_name" placeholder="Select account" />
                    </div>
                    <div v-if="form.type === 'customer'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
                        <div class="flex gap-1.5">
                            <div class="flex-1"><SearchSelect :options="customers" v-model="selectedCustomer" label="display_name" placeholder="Select customer" @update:model-value="onChangeCustomer" /></div>
                            <button type="button" title="Quick Add Customer" @click="showQuickAdd = true" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-slate-600 hover:bg-slate-50">
                                <i class="bi bi-person-plus"></i>
                            </button>
                            <button v-if="selectedCustomer" type="button" title="Quick Ledger" @click="showQuickLedger = true" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-slate-600 hover:bg-slate-50">
                                <i class="bi bi-journal-text"></i>
                            </button>
                        </div>
                    </div>
                    <div v-if="activeMode === 'receive' && form.type === 'customer' && selectedCustomer && careflowDueOrders.length" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        This customer has due CareFlow orders. Entering an amount here and clicking Save records a general receipt only —
                        it will <strong>not</strong> settle a CareFlow order's due. Use the "CareFlow Orders Due" table below to receive payment against an order.
                    </div>
                    <div v-if="form.type === 'supplier'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Supplier</label>
                        <div class="flex gap-1.5">
                            <div class="flex-1"><SearchSelect :options="suppliers" v-model="selectedSupplier" label="display_name" placeholder="Select supplier" @update:model-value="onChangeSupplier" /></div>
                            <button type="button" title="Quick Add Supplier" @click="showQuickAdd = true" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-slate-600 hover:bg-slate-50">
                                <i class="bi bi-person-plus"></i>
                            </button>
                            <button v-if="selectedSupplier" type="button" title="Quick Ledger" @click="showQuickLedger = true" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-slate-600 hover:bg-slate-50">
                                <i class="bi bi-journal-text"></i>
                            </button>
                        </div>
                    </div>
                    <div v-if="form.type === 'provider'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Provider</label>
                        <div class="flex gap-1.5">
                            <div class="flex-1"><SearchSelect :options="providers" v-model="selectedProvider" label="name" placeholder="Select provider" @update:model-value="onChangeProvider" /></div>
                            <button v-if="selectedProvider" type="button" title="Quick Ledger" @click="showQuickLedger = true" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-slate-600 hover:bg-slate-50">
                                <i class="bi bi-journal-text"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Due is calculated from item processing fees assigned to this provider, minus payments already made, plus any amount received back from them.</p>
                    </div>
                    <div v-if="form.type === 'employee'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Employee</label>
                        <SearchSelect :options="employees" v-model="selectedEmployee" label="display_name" placeholder="Select employee" @update:model-value="onChangeEmployee" />
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                        <input type="date" v-model="form.date" :readonly="dateLocked" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" :class="dateLocked ? 'bg-slate-50' : ''" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Prev. Due</label>
                        <input type="number" step="any" readonly :value="form.previous_due" class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input type="text" v-model="form.note" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Amount</label>
                        <input type="number" step="any" min="0.01" v-model="form.amount" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                        <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                            {{ form.id == '' ? 'Save' : 'Update' }}
                        </button>
                        <button type="button" :disabled="onProgress" @click="saveData(true)" class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
                            <i class="bi bi-printer"></i> {{ form.id == '' ? 'Save' : 'Update' }} &amp; Print
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div v-if="activeMode === 'receive' && form.type === 'customer' && selectedCustomer && careflowDueOrders.length" class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-2.5"><h3 class="text-sm font-semibold text-slate-800">CareFlow Orders Due</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Invoice</th>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Total</th>
                            <th class="px-3 py-2 font-medium">Due</th>
                            <th class="px-3 py-2 font-medium">Amount</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in careflowDueOrders" :key="o.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium">{{ o.invoice }}</td>
                            <td class="px-3 py-2">{{ o.order_date }}</td>
                            <td class="px-3 py-2 capitalize">{{ o.status.replace(/_/g, ' ') }}</td>
                            <td class="px-3 py-2">{{ o.total }}</td>
                            <td class="px-3 py-2 font-semibold text-amber-700">{{ o.due }}</td>
                            <td class="px-3 py-2">
                                <input type="number" min="0" step="any" v-model="careflowPayAmount[o.id]" class="w-28 rounded-md border border-slate-300 px-2 py-1 text-sm" />
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button type="button" :disabled="careflowPaying === o.id" @click="payCareflowOrder(o)" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                                    Receive &amp; Process
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
                            Date From
                            <button
                                v-for="shortcut in RANGE_SHORTCUTS"
                                :key="shortcut.label"
                                type="button"
                                @click="applyRange(shortcut.days)"
                                :class="
                                    isActiveRange(shortcut.days)
                                        ? 'border-brand-500 bg-brand-500 text-white'
                                        : 'border-slate-300 text-slate-500 hover:border-brand-400 hover:text-brand-600'
                                "
                                class="rounded border px-1.5 py-0.5 text-[10px] font-medium"
                            >
                                {{ shortcut.label }}
                            </button>
                        </label>
                        <input type="date" v-model="dateFrom" @change="load" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date To</label>
                        <input type="date" v-model="dateTo" @change="load" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input type="text" v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-slate-500">{{ filteredRows.length }} records</span>
                    <button type="button" @click="exportExcel" title="Export Excel" class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-500 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" @click="print" title="Print" class="text-slate-500 hover:text-brand-500">
                        <i class="bi bi-printer text-lg"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Invoice</th>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">{{ partyTypeLabel }}</th>
                            <th class="px-3 py-2 font-medium">Method</th>
                            <th class="px-3 py-2 font-medium">Amount</th>
                            <th class="px-3 py-2 font-medium">Note</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2">{{ row.invoice }}</td>
                            <td class="px-3 py-2">{{ row.date }}</td>
                            <td class="px-3 py-2">{{ row.name }}</td>
                            <td class="px-3 py-2 capitalize">{{ row.payment_method }}</td>
                            <td class="px-3 py-2">{{ row.amount }}</td>
                            <td class="px-3 py-2">{{ row.note }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i @click="openRecord(row.id)" title="print" class="bi bi-printer cursor-pointer text-slate-500 hover:text-brand-500"></i>
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">No records found</td>
                        </tr>
                        <tr v-else class="bg-slate-50 font-semibold">
                            <td colspan="4" class="px-3 py-2 text-right">Total</td>
                            <td class="px-3 py-2">{{ totalAmount }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ReceiveQuickView ref="quickViewRef" v-model="showQuickView" :record-id="quickViewRecordId" :mode="activeMode" />
        <QuickAddParty v-model="showQuickAdd" :mode="form.type" @created="onPartyCreated" />
        <QuickLedger v-model="showQuickLedger" :mode="form.type" :party-id="selectedParty?.id" :party-name="selectedParty?.name" />
    </div>
</template>
