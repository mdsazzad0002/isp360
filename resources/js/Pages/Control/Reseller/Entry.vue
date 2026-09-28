<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import * as XLSX from 'xlsx';
import SearchSelect from '../../../Components/SearchSelect.vue';
import Pagination from '../../../Components/Pagination.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { resizeImageFile } from '../../../lib/imageResize';
import ResellerLedgerOffcanvas from './ResellerLedgerOffcanvas.vue';
import { cur, moneyStep } from '../../../lib/isp';

defineOptions({ layout: AppLayout });

// reseller levels from ISP Settings: 1 = no sub-resellers
const props = defineProps({ maxDepth: { type: Number, default: 1 } });

const toast = useToast();
const page = usePage();

const showLedgerOffcanvas = ref(false);
const ledgerReseller = ref(null);
function openLedger(row) {
    ledgerReseller.value = row;
    showLedgerOffcanvas.value = true;
}

function emptyForm() {
    return {
        id: '',
        name: '',
        phone: '',
        email: '',
        username: '',
        password: '',
        address: '',
        status: 'a',
        image: '',
        parent_id: '',
        credit_limit: '',
    };
}

const form = reactive(emptyForm());
const areas = ref([]);
const selectedArea = ref(null);
const rows = ref([]);
const currentPage = ref(1);
const perPage = 10;
const totalPages = ref(0);
const filter = ref('');
const imageSrc = ref('/noImage.jpg');
const onProgress = ref(false);
let filterTimeout = null;

// possible parents: resellers with room below them (the server checks the rest)
const allResellers = ref([]);
const parentOptions = computed(() => allResellers.value.filter((r) => r.id !== form.id && (r.depth ?? 1) < props.maxDepth));
function getResellers() {
    axios.post('/get-reseller').then((res) => (allResellers.value = res.data));
}

function getAreas() {
    axios.post('/get-area').then((res) => {
        areas.value = res.data;
    });
}

function load() {
    axios
        .post('/get-reseller', {
            page: currentPage.value,
            per_page: perPage,
            search: filter.value,
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

function changePage(p) {
    currentPage.value = p;
    load();
}

function resetForm() {
    Object.assign(form, emptyForm());
    selectedArea.value = null;
    imageSrc.value = '/noImage.jpg';
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-reseller' : '/reseller';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { ...form, area_id: selectedArea.value ? selectedArea.value.id : '' });
        toast.success(res.data.message);
        resetForm();
        load();
        getResellers();
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
        phone: row.phone,
        email: row.email,
        username: row.username,
        password: '',
        address: row.address,
        status: row.status,
        image: row.image,
        parent_id: row.parent_id ?? '',
        credit_limit: row.credit_limit ?? '',
    });
    selectedArea.value = { id: row.area_id, name: row.area?.name };
    imageSrc.value = row.image ? '/' + row.image : '/noImage.jpg';
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-reseller', { id });
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

function exportExcel() {
    const params = new URLSearchParams();
    if (filter.value !== '') params.append('search', filter.value);
    window.location.href = `/reseller-export-excel?${params.toString()}`;
}

/* ---------------- Bulk import ---------------- */

const showImportPanel = ref(false);
const importMode = ref('file');
const rawPaste = ref('');
const fileRows = ref([]);
const fileName = ref('');
const importing = ref(false);
const importProgress = ref(0);
const importDone = ref(false);
const importSummary = ref({ inserted: 0, skipped: 0, errors: [], credentials: [] });

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
const previewColumns = computed(() => (previewRows.value.length ? Object.keys(previewRows.value[0]).filter((c) => c.toLowerCase() !== 'password') : []));
const missingColumns = computed(() => {
    if (!previewRows.value.length) return [];
    const cols = Object.keys(previewRows.value[0]).map((c) => c.toLowerCase());
    return ['name', 'phone', 'username'].filter((c) => !cols.includes(c) && !(c === 'phone' && cols.includes('mobile')));
});

const DEMO_COLUMNS = ['name', 'phone', 'username', 'password', 'email', 'area', 'address', 'status'];
const DEMO_ROWS = [
    ['Rahim Net', '01711000001', 'rahimnet', 'rahim@123', 'rahim@example.com', 'Mirpur', 'House 5, Road 2', 'active'],
    ['Karim Broadband', '01711000002', 'karimbb', '', '', 'Dhanmondi', 'Shop 12, Market Road', 'active'],
];

function downloadDemoTemplate() {
    const worksheet = XLSX.utils.aoa_to_sheet([DEMO_COLUMNS, ...DEMO_ROWS]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Resellers');
    XLSX.writeFile(workbook, 'reseller-import-demo.xlsx');
}

function downloadCredentials() {
    const rows = importSummary.value.credentials.map((c) => [c.code, c.name, c.username, c.password]);
    const worksheet = XLSX.utils.aoa_to_sheet([['code', 'name', 'username', 'password'], ...rows]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Logins');
    XLSX.writeFile(workbook, 'reseller-logins.xlsx');
}

function openImportPanel() {
    showImportPanel.value = true;
    importMode.value = 'file';
    rawPaste.value = '';
    fileRows.value = [];
    fileName.value = '';
    importing.value = false;
    importProgress.value = 0;
    importDone.value = false;
    importSummary.value = { inserted: 0, skipped: 0, errors: [], credentials: [] };
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
        // raw: false keeps phone numbers as text (e.g. the leading 0 in 017...)
        fileRows.value = XLSX.utils.sheet_to_json(sheet, { defval: '', raw: false });
    };
    reader.readAsArrayBuffer(file);
}

async function startImport() {
    const rowsToImport = previewRows.value;
    if (!rowsToImport.length) {
        toast.error('No rows to import. Paste rows or select a file first.');
        return;
    }
    if (missingColumns.value.length) {
        toast.error(`Missing column(s): ${missingColumns.value.join(', ')}`);
        return;
    }

    importing.value = true;
    importDone.value = false;
    importProgress.value = 0;
    importSummary.value = { inserted: 0, skipped: 0, errors: [], credentials: [] };

    const size = 25;
    for (let offset = 0; offset < rowsToImport.length; offset += size) {
        try {
            const res = await axios.post('/reseller-import-batch', { rows: rowsToImport.slice(offset, offset + size), offset });
            importSummary.value.inserted += res.data.inserted || 0;
            importSummary.value.skipped += res.data.skipped || 0;
            importSummary.value.errors.push(...(res.data.errors || []));
            importSummary.value.credentials.push(...(res.data.credentials || []));
        } catch (error) {
            importSummary.value.errors.push(error.response?.data?.message || String(error));
        }
        importProgress.value = Math.round((Math.min(offset + size, rowsToImport.length) / rowsToImport.length) * 100);
    }

    importing.value = false;
    importDone.value = true;

    if (importSummary.value.inserted > 0) {
        toast.success(`${importSummary.value.inserted} reseller(s) imported successfully.`);
        currentPage.value = 1;
        load();
    } else {
        toast.error('No resellers were imported.');
    }
}

onMounted(() => {
    getAreas();
    load();
    getResellers();
});
</script>

<template>
    <div class="mx-auto p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Reseller Entry</h1>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Mobile</label>
                        <input type="text" autocomplete="off" v-model="form.phone" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Email <span class="font-normal text-slate-400">(Optional)</span></label>
                        <input type="email" autocomplete="off" v-model="form.email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area <span class="font-normal text-slate-400">(Optional)</span></label>
                        <SearchSelect :options="areas" v-model="selectedArea" label="name" placeholder="Select area" />
                    </div>
                    <div v-if="maxDepth > 1 || form.parent_id">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Parent reseller <span class="font-normal text-slate-400">(empty = settles with the company)</span></label>
                        <select v-model="form.parent_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">— Top level —</option>
                            <option v-for="r in parentOptions" :key="r.id" :value="r.id">{{ '— '.repeat((r.depth ?? 1) - 1) }}{{ r.name }} ({{ r.code }})</option>
                        </select>
                    </div>
                </div>
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Address</label>
                        <input type="text" autocomplete="off" v-model="form.address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Portal Username</label>
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
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Credit limit ({{ cur() }}) <span class="font-normal text-slate-400">(empty = no limit)</span></label>
                        <input type="number" min="0" :step="moneyStep()" v-model="form.credit_limit" placeholder="Most cash the reseller may hold before settling" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
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
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                    <input type="text" v-model="filter" @input="onFilterInput" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="openImportPanel" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                        <i class="bi bi-upload"></i> Bulk Import
                    </button>
                    <button type="button" @click="exportExcel" title="Export Excel" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
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
                            <th class="px-2 py-2 font-medium">Username</th>
                            <th class="px-2 py-2 font-medium">Mobile</th>
                            <th class="px-2 py-2 font-medium">Area</th>
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
                                <div v-if="row.parent" class="text-xs text-slate-400">under {{ row.parent.name }}</div>
                            </td>
                            <td class="px-2 py-1.5">{{ row.username }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="px-2 py-1.5">{{ row.area?.name }}</td>
                            <td class="px-2 py-1.5">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="row.status === 'a' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ row.status === 'a' ? 'Active' : 'Deactive' }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="flex justify-end gap-3">
                                    <a
                                        v-if="page.props.canResellerLoginAs"
                                        :href="`/reseller/${row.id}/login-as`"
                                        target="_blank"
                                        rel="noopener"
                                        title="Login to this reseller's portal"
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
        <!-- Bulk import offcanvas -->
        <Teleport to="body">
            <div v-if="showImportPanel" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-slate-900/50" @click="closeImportPanel"></div>
                <div class="relative flex h-full w-full max-w-3xl flex-col bg-white shadow-2xl animate-slide-in">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <h2 class="text-base font-bold text-slate-800">Bulk Import Resellers</h2>
                        <button type="button" @click="closeImportPanel" :disabled="importing" class="text-slate-400 hover:text-slate-600 disabled:opacity-40">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <template v-if="!importDone">
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
                                <div class="text-sm text-brand-800">
                                    <i class="bi bi-info-circle"></i>
                                    <span class="font-semibold">The first row must be the column names</span> (header row), and every row after it is treated as a reseller.
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
                                    @click="importMode = 'file'"
                                    class="flex-1 rounded px-3 py-1.5 font-medium transition"
                                    :class="importMode === 'file' ? 'bg-brand-500 text-white' : 'text-slate-500 hover:bg-slate-50'"
                                >
                                    Upload File
                                </button>
                                <button
                                    type="button"
                                    @click="importMode = 'paste'"
                                    class="flex-1 rounded px-3 py-1.5 font-medium transition"
                                    :class="importMode === 'paste' ? 'bg-brand-500 text-white' : 'text-slate-500 hover:bg-slate-50'"
                                >
                                    Paste Rows
                                </button>
                            </div>

                            <div v-if="importMode === 'file'">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Select a CSV or Excel file. <span class="font-semibold">The first row must be the column names.</span></label>
                                <input type="file" accept=".csv,.xlsx,.xls" @change="onFileChange" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                <p v-if="fileName" class="mt-1 text-xs text-slate-500"><i class="bi bi-file-earmark-check"></i> {{ fileName }}: {{ fileRows.length }} row(s)</p>
                            </div>
                            <div v-else>
                                <label class="mb-1 block text-xs font-medium text-slate-600">
                                    Copy rows from Excel, <span class="font-semibold">including the column-name row</span>, and paste them below. The preview updates automatically.
                                </label>
                                <textarea
                                    v-model="rawPaste"
                                    rows="10"
                                    placeholder="name&#9;phone&#9;username&#9;password&#10;Rahim Net&#9;01711000001&#9;rahimnet&#9;rahim@123"
                                    class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                                ></textarea>
                            </div>

                            <div class="mt-3 space-y-1 text-xs text-slate-500">
                                <p>Required columns: <span class="font-semibold">name, phone, username</span>. Optional: password, email, area, address, status (active/inactive).</p>
                                <p>Leave <span class="font-semibold">password</span> blank to auto-generate one. You can download the generated logins after the import.</p>
                                <p>Area must match an existing area name. Rows whose username or phone already exists are skipped.</p>
                            </div>

                            <p v-if="missingColumns.length" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                <i class="bi bi-exclamation-triangle"></i> Missing required column(s): <span class="font-semibold">{{ missingColumns.join(', ') }}</span>
                            </p>

                            <div v-if="previewRows.length" class="mt-4">
                                <div class="mb-1.5 text-xs font-bold uppercase tracking-wide text-slate-400">
                                    Preview ({{ previewRows.length }} row{{ previewRows.length === 1 ? '' : 's' }}, passwords hidden)
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
                            <div v-if="importSummary.credentials.length" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                                <div class="text-sm text-amber-800">
                                    <i class="bi bi-key"></i>
                                    <span class="font-semibold">{{ importSummary.credentials.length }}</span> reseller(s) got an auto-generated password.
                                    Download the list now, because it can't be shown again.
                                </div>
                                <button type="button" @click="downloadCredentials" class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-600">
                                    <i class="bi bi-download"></i> Download Logins
                                </button>
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
                            :disabled="importing || previewRows.length === 0 || missingColumns.length > 0"
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
        <ResellerLedgerOffcanvas v-model="showLedgerOffcanvas" :reseller="ledgerReseller ?? {}" />
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

