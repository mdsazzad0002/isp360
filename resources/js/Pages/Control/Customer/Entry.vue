<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { usePage, Link } from '@inertiajs/vue3';
import * as XLSX from 'xlsx';
import SearchSelect from '../../../Components/SearchSelect.vue';
import Pagination from '../../../Components/Pagination.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CustomerIspLedgerOffcanvas from './CustomerIspLedgerOffcanvas.vue';
import { useToast } from '../../../lib/toast';
import { resizeImageFile } from '../../../lib/imageResize';
import { printDocument } from '../../../lib/print';

defineOptions({ layout: AppLayout });

const toast = useToast();
const page = usePage();
// country pack: address labels and the phone example (HandleInertiaRequests "region")
const region = computed(() => page.props.region || {});

function emptyForm() {
    return {
        id: '',
        name: '',
        owner: '',
        email: '',
        phone: '',
        type: 'retail',
        address: '',
        city: '',
        state: '',
        postcode: '',
        language: '',
        previous_due: 0,
        credit_limit: 0,
        is_membership: 'no',
        amount: 0,
        status: 'a',
        image: '',
        username: '',
        password: '',
        nid: '',
        date_of_birth: '',
        billing_address: '',
        notes: '',
        account_status: 'active',
    };
}

const form = reactive(emptyForm());
const areas = ref([]);
const selectedArea = ref(null);
const boxes = ref([]);
const selectedBox = ref(null);
const resellers = ref([]);
const selectedReseller = ref(null);
const rows = ref([]);
const currentPage = ref(1);
const perPage = 10;
const totalPages = ref(0);
const filter = ref('');
const customerTypeFilter = ref('');
const selectedAreaFilter = ref(null);
const imageSrc = ref('/noImage.jpg');
const onProgress = ref(false);
let filterTimeout = null;

const showLedgerOffcanvas = ref(false);
const ledgerCustomer = ref(null);

function openLedger(row) {
    ledgerCustomer.value = row;
    showLedgerOffcanvas.value = true;
}

function getAreas() {
    axios.post('/get-area').then((res) => {
        areas.value = res.data;
    });
}

function getBoxes() {
    axios.post('/isp/get-boxes').then((res) => {
        boxes.value = res.data;
    });
}

// Picking a box fills its area (and the area's zone) so the location hierarchy stays consistent.
function onBoxChange(box) {
    if (box?.area_id) {
        const area = areas.value.find((a) => a.id === box.area_id);
        if (area) selectedArea.value = area;
    }
}

function getResellers() {
    axios.post('/get-reseller', { forSearch: true }).then((res) => {
        resellers.value = res.data;
    });
}

/* ---------------- Quick area entry ---------------- */

const showQuickArea = ref(false);
const quickAreaName = ref('');
const quickAreaSaving = ref(false);

function openQuickArea() {
    quickAreaName.value = '';
    showQuickArea.value = true;
}

function closeQuickArea() {
    if (quickAreaSaving.value) return;
    showQuickArea.value = false;
}

async function saveQuickArea() {
    if (!quickAreaName.value.trim()) {
        toast.error('Area name is required');
        return;
    }
    quickAreaSaving.value = true;
    try {
        const res = await axios.post('/area', { name: quickAreaName.value.trim() });
        toast.success(res.data.message);
        await new Promise((resolve) => {
            axios.post('/get-area').then((r) => {
                areas.value = r.data;
                resolve();
            });
        });
        const created = areas.value.find((a) => a.name === quickAreaName.value.trim());
        if (created) selectedArea.value = created;
        showQuickArea.value = false;
    } catch (err) {
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    } finally {
        quickAreaSaving.value = false;
    }
}

function load() {
    axios
        .post('/get-customer', {
            page: currentPage.value,
            per_page: perPage,
            search: filter.value,
            customer_type: customerTypeFilter.value,
            areaId: selectedAreaFilter.value ? selectedAreaFilter.value.id : '',
        })
        .then((res) => {
            totalPages.value = res.data.last_page;
            rows.value = res.data.data.map((item, index) => ({
                ...item,
                sl: (res.data.current_page - 1) * perPage + index + 1,
            }));
        });
}

function onFilterInput() {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        currentPage.value = 1;
        load();
    }, 300);
}

function onFilterChange() {
    currentPage.value = 1;
    load();
}

function changePage(p) {
    currentPage.value = p;
    load();
}

async function printList() {
    const res = await axios.post('/get-customer', {
        search: filter.value,
        customer_type: customerTypeFilter.value,
        areaId: selectedAreaFilter.value ? selectedAreaFilter.value.id : '',
    });
    const items = res.data;

    const rowsHtml = items.length
        ? items
              .map(
                  (item, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>${item.code ?? ''}</td>
                    <td>${item.name ?? ''}</td>
                    <td>${item.owner ?? ''}</td>
                    <td>${item.type ?? ''}</td>
                    <td>${item.phone ?? ''}</td>
                    <td>${item.area?.name ?? ''}</td>
                    <td>${item.address ?? ''}</td>
                </tr>`
              )
              .join('')
        : '<tr><td colspan="8" style="text-align:center;">Not Found Data</td></tr>';

    const bodyHtml = `
        <table>
            <thead><tr><th>Sl</th><th>Code</th><th>Name</th><th>Owner</th><th>Type</th><th>Phone</th><th>Area</th><th>Address</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument('Customer List', bodyHtml, page.props.company);
}

function exportExcel() {
    const params = new URLSearchParams();
    if (filter.value !== '') params.append('search', filter.value);
    if (customerTypeFilter.value !== '') params.append('customer_type', customerTypeFilter.value);
    if (selectedAreaFilter.value) params.append('areaId', selectedAreaFilter.value.id);
    window.location.href = `/customer/export-excel?${params.toString()}`;
}

/* ---------------- Import offcanvas ---------------- */

const showImportPanel = ref(false);
const importMode = ref('paste');
const rawPaste = ref('');
const fileRows = ref([]);
const fileName = ref('');
const importing = ref(false);
const importProgress = ref(0);
const importDone = ref(false);
const importSummary = ref({ inserted: 0, skipped: 0, errors: [] });

function splitLine(line) {
    return line.includes('\t') ? line.split('\t') : line.split(',');
}

function parsePaste(text) {
    const lines = text.split(/\r\n|\n|\r/).filter((l) => l.trim() !== '');
    if (lines.length < 2) return [];
    const headers = splitLine(lines[0]).map((h) => h.trim());
    return lines.slice(1).map((line) => {
        const cells = splitLine(line);
        const obj = {};
        headers.forEach((h, i) => (obj[h] = (cells[i] ?? '').trim()));
        return obj;
    });
}

const previewRows = computed(() => (importMode.value === 'file' ? fileRows.value : parsePaste(rawPaste.value)));
const previewColumns = computed(() => (previewRows.value.length ? Object.keys(previewRows.value[0]) : []));

const DEMO_COLUMNS = ['name', 'phone', 'owner', 'type', 'email', 'area', 'address', 'code', 'previous_due', 'credit_limit'];
const DEMO_ROWS = [
    ['Karim Store', '01711000001', 'Abdul Karim', 'retail', 'karim@example.com', 'Mirpur', 'House 5, Road 2', '', 0, 0],
    ['Rahim Traders', '01711000002', 'Abdur Rahim', 'wholesale', '', 'Dhanmondi', 'Shop 12, Market Road', '', 0, 5000],
];

function downloadDemoTemplate() {
    const worksheet = XLSX.utils.aoa_to_sheet([DEMO_COLUMNS, ...DEMO_ROWS]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Customers');
    XLSX.writeFile(workbook, 'customer-import-demo.xlsx');
}

function openImportPanel() {
    showImportPanel.value = true;
    importMode.value = 'paste';
    rawPaste.value = '';
    fileRows.value = [];
    fileName.value = '';
    importing.value = false;
    importProgress.value = 0;
    importDone.value = false;
    importSummary.value = { inserted: 0, skipped: 0, errors: [] };
}

function closeImportPanel() {
    if (importing.value) return;
    showImportPanel.value = false;
}

function onFileChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    fileName.value = file.name;
    const reader = new FileReader();
    reader.onload = (ev) => {
        const workbook = XLSX.read(ev.target.result, { type: 'array' });
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        fileRows.value = XLSX.utils.sheet_to_json(sheet, { defval: '' });
    };
    reader.readAsArrayBuffer(file);
}

function chunk(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
    return out;
}

async function startImport() {
    const rowsToImport = previewRows.value;
    if (!rowsToImport.length) {
        toast.error('No rows to import. Paste rows or select a file first.');
        return;
    }

    importing.value = true;
    importDone.value = false;
    importProgress.value = 0;
    importSummary.value = { inserted: 0, skipped: 0, errors: [] };

    const batches = chunk(rowsToImport, 25);
    for (let i = 0; i < batches.length; i++) {
        try {
            const res = await axios.post('/customer/import-batch', { rows: batches[i] });
            importSummary.value.inserted += res.data.inserted || 0;
            importSummary.value.skipped += res.data.skipped || 0;
            importSummary.value.errors.push(...(res.data.errors || []));
        } catch (error) {
            importSummary.value.errors.push(error.response?.data?.message || String(error));
        }
        importProgress.value = Math.round(((i + 1) / batches.length) * 100);
    }

    importing.value = false;
    importDone.value = true;

    if (importSummary.value.inserted > 0) {
        toast.success(`${importSummary.value.inserted} customer(s) imported successfully.`);
        currentPage.value = 1;
        load();
    } else {
        toast.error('No customers were imported.');
    }
}

function resetForm() {
    Object.assign(form, emptyForm());
    selectedArea.value = null;
    selectedBox.value = null;
    selectedReseller.value = null;
    imageSrc.value = '/noImage.jpg';
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-customer' : '/customer';
    onProgress.value = true;
    try {
        const res = await axios.post(url, {
            ...form,
            area_id: selectedArea.value ? selectedArea.value.id : '',
            zone_id: selectedArea.value ? (areas.value.find((a) => a.id === selectedArea.value.id)?.zone_id ?? null) : null,
            box_id: selectedBox.value ? selectedBox.value.id : null,
            reseller_id: selectedReseller.value ? selectedReseller.value.id : '',
        });
        toast.success(res.data.message);
        resetForm();
        load();
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
        name: row.name,
        owner: row.owner,
        email: row.email,
        phone: row.phone,
        type: row.type,
        address: row.address,
        city: row.city ?? '',
        state: row.state ?? '',
        postcode: row.postcode ?? '',
        language: row.language ?? '',
        previous_due: row.previous_due,
        credit_limit: row.credit_limit,
        is_membership: row.is_membership,
        amount: row.amount,
        status: row.status,
        image: row.image,
        username: row.username ?? '',
        password: '',
        nid: row.nid ?? '',
        date_of_birth: row.date_of_birth ?? '',
        billing_address: row.billing_address ?? '',
        notes: row.notes ?? '',
        account_status: row.account_status ?? 'active',
    });
    selectedBox.value = row.box_id ? boxes.value.find((b) => b.id === row.box_id) || null : null;
    selectedArea.value = { id: row.area_id, name: row.area?.name };
    selectedReseller.value = row.reseller_id ? { id: row.reseller_id, name: row.reseller?.name } : null;
    imageSrc.value = row.image ? '/' + row.image : '/noImage.jpg';
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-customer', { id });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

async function onImageChange(e) {
    const file = e.target.files[0];
    if (!file) {
        e.target.value = '';
        return;
    }
    const { file: resized, dataUrl } = await resizeImageFile(file, 150, 150);
    imageSrc.value = dataUrl;
    form.image = resized;
}

onMounted(() => {
    getAreas();
    getBoxes();
    getResellers();
    load();
});
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Customer Entry</h1>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">NID</label>
                            <input type="text" autocomplete="off" v-model="form.nid" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Date of Birth</label>
                            <input type="date" v-model="form.date_of_birth" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Service Address</label>
                        <input type="text" autocomplete="off" v-model="form.address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">City</label>
                            <input type="text" autocomplete="off" v-model="form.city" maxlength="100" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ region.state_label || 'State' }}</label>
                            <input type="text" autocomplete="off" v-model="form.state" maxlength="100" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="w-28">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ region.postcode_label || 'Postcode' }}<span v-if="region.postcode_required" class="text-red-500">*</span></label>
                            <input type="text" autocomplete="off" v-model="form.postcode" maxlength="20" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Billing Address <span class="font-normal text-slate-400">(if different)</span></label>
                        <input type="text" autocomplete="off" v-model="form.billing_address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label class="block text-xs font-medium text-slate-600">Area</label>
                            <button type="button" @click="openQuickArea" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                                <i class="bi bi-plus-circle"></i> New Area
                            </button>
                        </div>
                        <SearchSelect :options="areas" v-model="selectedArea" label="name" placeholder="Select area" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Box</label>
                        <SearchSelect :options="boxes" v-model="selectedBox" label="display_name" placeholder="Select box" @update:model-value="onBoxChange" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Mobile <span class="font-normal text-slate-400">· SMS language</span></label>
                        <select v-model="form.language" class="mb-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">Default templates</option>
                            <option value="en">English</option>
                            <option value="bn">বাংলা</option>
                            <option value="hi">हिन्दी</option>
                            <option value="ar">العربية</option>
                        </select>
                        <input type="tel" autocomplete="off" v-model="form.phone" :placeholder="region.phone_example ? `${region.phone_example} or +${region.calling_code}…` : ''" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Reseller <span class="font-normal text-slate-400">(Optional)</span></label>
                        <SearchSelect :options="resellers" v-model="selectedReseller" label="name" placeholder="Select reseller" />
                    </div>
                </div>
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Email <span class="font-normal text-slate-400">(Optional)</span></label>
                        <input type="email" autocomplete="off" v-model="form.email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Portal Username <span class="font-normal text-slate-400">(Optional)</span></label>
                            <input type="text" autocomplete="off" v-model="form.username" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Portal Password</label>
                            <input
                                type="password"
                                autocomplete="new-password"
                                v-model="form.password"
                                :placeholder="form.id == '' ? '' : 'Leave blank to keep current'"
                                class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                            />
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Opening Due</label>
                            <input type="number" min="0" step="any" v-model="form.previous_due" :disabled="form.id != ''" :title="form.id != '' ? 'Opening due is billed once on creation. Use a manual invoice or credit note to correct it.' : 'Creates an opening balance invoice'" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:bg-slate-50" />
                        </div>
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Credit Limit</label>
                            <input type="number" min="0" step="any" v-model="form.credit_limit" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Account Status</label>
                            <select v-model="form.account_status" class="mb-2 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Is Member</label>
                            <select v-model="form.is_membership" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        <div v-if="form.is_membership === 'yes'" class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Amount</label>
                            <input type="number" min="0" step="any" v-model="form.amount" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Notes</label>
                        <input type="text" autocomplete="off" v-model="form.notes" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Customer Type</label>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-1.5"><input type="radio" value="retail" v-model="form.type" /> Retail</label>
                            <label class="flex items-center gap-1.5"><input type="radio" value="wholesale" v-model="form.type" /> Wholesale</label>
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" true-value="a" false-value="p" v-model="form.status" />
                            IsActive
                        </label>
                        <div class="flex gap-2">
                            <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                            <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ form.id == '' ? 'Save' : 'Update' }}
                            </button>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <p class="mb-1 text-xs text-red-500">(150 x 150) PX</p>
                    <img :src="imageSrc" class="mb-2 h-24 w-24 rounded-md border border-slate-200 object-cover" />
                    <label class="mb-1 block text-xs font-medium text-slate-600">Upload Image</label>
                    <input type="file" @change="onImageChange" class="w-full text-xs" />
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input type="text" v-model="filter" @input="onFilterInput" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                        <select v-model="customerTypeFilter" @change="onFilterChange" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option value="retail">Retail</option>
                            <option value="wholesale">Wholesale</option>
                        </select>
                    </div>
                    <div class="w-56">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                        <SearchSelect :options="areas" v-model="selectedAreaFilter" label="name" placeholder="All areas" @update:model-value="onFilterChange" />
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/deleted-customer-record" title="Deleted Customer Record" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                        <i class="bi bi-arrow-counterclockwise"></i> Deleted Records
                    </Link>
                    <button type="button" @click="openImportPanel" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                        <i class="bi bi-upload"></i> Import
                    </button>
                    <button type="button" @click="exportExcel" title="Export Excel" class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-500 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" @click="printList" title="Print" class="text-slate-500 hover:text-brand-500">
                        <i class="bi bi-printer text-lg"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Sl</th>
                            <th class="px-2 py-2 font-medium">Code</th>
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Owner</th>
                            <th class="px-2 py-2 font-medium">Type</th>
                            <th class="px-2 py-2 font-medium">Mobile</th>
                            <th class="px-2 py-2 font-medium">Area</th>
                            <th class="px-2 py-2 font-medium">Current Due</th>
                            <th class="px-2 py-2 font-medium">Credit Limit</th>
                            <th class="px-2 py-2 font-medium">Member</th>
                            <th class="px-2 py-2 font-medium">Point</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.sl }}</td>
                            <td class="px-2 py-1.5">{{ row.code }}</td>
                            <td class="px-2 py-1.5">
                                <button type="button" class="text-start font-medium text-brand-600 hover:underline" title="Open ledger" @click="openLedger(row)">{{ row.name }}</button>
                            </td>
                            <td class="px-2 py-1.5">{{ row.owner }}</td>
                            <td class="px-2 py-1.5 capitalize">{{ row.type }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="px-2 py-1.5">{{ row.area?.name }}</td>
                            <td class="px-2 py-1.5"><span :class="Number(row.ledger_balance) > 0 ? 'font-medium text-red-600' : ''">{{ row.ledger_balance }}</span></td>
                            <td class="px-2 py-1.5">{{ row.credit_limit }}</td>
                            <td class="px-2 py-1.5">{{ row.is_membership === 'yes' ? 'Yes' : 'No' }}</td>
                            <td class="px-2 py-1.5">{{ row.point }}</td>
                            <td class="px-2 py-1.5">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="row.status === 'a' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ row.status === 'a' ? 'Active' : 'Deactive' }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="flex justify-end gap-3">
                                    <Link :href="`/isp/customer/${row.id}`" title="Customer 360 (connections, invoices, payments, ledger)" class="text-slate-500 hover:text-brand-600"><i class="bi bi-person-lines-fill"></i></Link>
                                    <a
                                        v-if="page.props.canCustomerLoginAs"
                                        :href="`/customer/${row.id}/login-as`"
                                        target="_blank"
                                        rel="noopener"
                                        title="Login to this customer's portal"
                                        class="text-emerald-600 hover:text-emerald-700"
                                    >
                                        <i class="bi bi-box-arrow-in-right"></i>
                                    </a>
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="currentPage" :total-pages="totalPages" @change="changePage" />
        </div>

        <!-- Quick area entry -->
        <Teleport to="body">
            <div v-if="showQuickArea" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0 bg-slate-900/50" @click="closeQuickArea"></div>
                <div class="relative w-full max-w-sm rounded-lg bg-white p-5 shadow-2xl">
                    <h2 class="mb-3 text-sm font-bold text-slate-800">New Area</h2>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Area Name</label>
                    <input
                        type="text"
                        autocomplete="off"
                        v-model="quickAreaName"
                        @keyup.enter="saveQuickArea"
                        class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                        placeholder="Enter area name"
                    />
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="closeQuickArea" :disabled="quickAreaSaving" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50">
                            Cancel
                        </button>
                        <button
                            type="button"
                            @click="saveQuickArea"
                            :disabled="quickAreaSaving"
                            class="inline-flex items-center gap-2 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                        >
                            <i v-if="quickAreaSaving" class="bi bi-arrow-repeat animate-spin"></i>
                            {{ quickAreaSaving ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Import offcanvas -->
        <Teleport to="body">
            <div v-if="showImportPanel" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-slate-900/50" @click="closeImportPanel"></div>
                <div class="relative flex h-full w-full max-w-3xl flex-col bg-white shadow-2xl animate-slide-in">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <h2 class="text-base font-bold text-slate-800">Import Customers</h2>
                        <button type="button" @click="closeImportPanel" :disabled="importing" class="text-slate-400 hover:text-slate-600 disabled:opacity-40">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <template v-if="!importDone">
                            <div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
                                <div class="text-sm text-brand-800">
                                    <i class="bi bi-info-circle"></i>
                                    <span class="font-semibold">The first row must be the column names</span> (header row) — every row after it is treated as a customer.
                                </div>
                                <button
                                    type="button"
                                    @click="downloadDemoTemplate"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-300 bg-white px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-100"
                                >
                                    <i class="bi bi-download"></i> Download Demo
                                </button>
                            </div>

                            <div class="mb-4 flex rounded-md border border-slate-200 p-1 text-sm">
                                <button
                                    type="button"
                                    @click="importMode = 'paste'"
                                    class="flex-1 rounded px-3 py-1.5 font-medium transition"
                                    :class="importMode === 'paste' ? 'bg-brand-500 text-white' : 'text-slate-500 hover:bg-slate-50'"
                                >
                                    Paste Rows
                                </button>
                                <button
                                    type="button"
                                    @click="importMode = 'file'"
                                    class="flex-1 rounded px-3 py-1.5 font-medium transition"
                                    :class="importMode === 'file' ? 'bg-brand-500 text-white' : 'text-slate-500 hover:bg-slate-50'"
                                >
                                    Upload File
                                </button>
                            </div>

                            <div v-if="importMode === 'paste'">
                                <label class="mb-1 block text-xs font-medium text-slate-600">
                                    Copy rows from Excel — <span class="font-semibold">the first pasted row must be the column names</span> — and paste below. Preview generates automatically.
                                </label>
                                <textarea
                                    v-model="rawPaste"
                                    rows="10"
                                    placeholder="name&#9;phone&#9;type&#9;area&#10;Karim Store&#9;01711000001&#9;retail&#9;Mirpur"
                                    class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                                ></textarea>
                            </div>
                            <div v-else>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Select a CSV or Excel file — <span class="font-semibold">the first row must be the column names</span></label>
                                <input type="file" accept=".csv,.xlsx,.xls" @change="onFileChange" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                <p v-if="fileName" class="mt-1 text-xs text-slate-500"><i class="bi bi-file-earmark-check"></i> {{ fileName }} — {{ fileRows.length }} row(s)</p>
                            </div>

                            <p class="mt-3 text-xs text-slate-500">
                                Required columns: <span class="font-semibold">name, phone</span>. Optional: owner, type (retail/wholesale), email, area,
                                address, code, previous_due, credit_limit. Unknown areas are created automatically.
                            </p>

                            <div v-if="previewRows.length" class="mt-4">
                                <div class="mb-1.5 flex items-center justify-between text-xs font-bold uppercase tracking-wide text-slate-400">
                                    <span>Preview ({{ previewRows.length }} row{{ previewRows.length === 1 ? '' : 's' }})</span>
                                </div>
                                <div class="max-h-80 overflow-auto rounded-md border border-slate-200">
                                    <table class="w-full text-xs">
                                        <thead class="sticky top-0 bg-slate-50">
                                            <tr>
                                                <th class="px-2 py-1.5 text-start font-medium text-slate-600">#</th>
                                                <th v-for="col in previewColumns" :key="col" class="px-2 py-1.5 text-start font-medium text-slate-600">{{ col }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(row, idx) in previewRows.slice(0, 20)" :key="idx" class="border-t border-slate-100">
                                                <td class="px-2 py-1 text-slate-400">{{ idx + 1 }}</td>
                                                <td v-for="col in previewColumns" :key="col" class="px-2 py-1 text-slate-700">{{ row[col] }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="previewRows.length > 20" class="mt-1 text-xs text-slate-400">Showing first 20 of {{ previewRows.length }} rows.</p>
                            </div>

                            <div v-if="importing" class="mt-4">
                                <div class="mb-1 flex justify-between text-xs text-slate-500">
                                    <span>Importing...</span>
                                    <span>{{ importProgress }}%</span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full bg-brand-500 transition-all duration-300" :style="{ width: importProgress + '%' }"></div>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-center">
                                <i class="bi bi-check-circle-fill text-3xl text-emerald-500"></i>
                                <p class="mt-2 text-sm font-semibold text-emerald-700">Import complete</p>
                                <p class="mt-1 text-xs text-emerald-600">{{ importSummary.inserted }} inserted, {{ importSummary.skipped }} skipped</p>
                            </div>
                            <div v-if="importSummary.errors.length" class="mt-3">
                                <div class="mb-1 text-xs font-bold uppercase tracking-wide text-slate-400">Issues</div>
                                <ul class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-700">
                                    <li v-for="(err, idx) in importSummary.errors" :key="idx">{{ err }}</li>
                                </ul>
                            </div>
                        </template>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                        <button
                            v-if="!importDone"
                            type="button"
                            @click="closeImportPanel"
                            :disabled="importing"
                            class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            v-if="!importDone"
                            type="button"
                            @click="startImport"
                            :disabled="importing || previewRows.length === 0"
                            class="inline-flex items-center gap-2 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                        >
                            <i v-if="importing" class="bi bi-arrow-repeat animate-spin"></i>
                            {{ importing ? 'Importing...' : `Import ${previewRows.length || ''}` }}
                        </button>
                        <button v-else type="button" @click="showImportPanel = false" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">
                            Done
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <CustomerIspLedgerOffcanvas v-model="showLedgerOffcanvas" :customer="ledgerCustomer ?? {}" @changed="load" />
    </div>
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
