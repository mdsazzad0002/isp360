<script setup>
import { reactive, ref, computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import * as XLSX from 'xlsx';
import Pagination from '../../Components/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { printDocument } from '../../lib/print';

defineOptions({ layout: AppLayout });

const props = defineProps({
    accountheads: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '', type: '', sortBy: 'id', sortDir: 'desc', perPage: 20 }) },
});

const toast = useToast();
const page = usePage();

const form = reactive({ id: '', name: '', type: 'expense' });
const onProgress = ref(false);

const rows = computed(() =>
    props.accountheads.data.map((item, index) => ({
        ...item,
        sl: (props.accountheads.current_page - 1) * props.accountheads.per_page + index + 1,
    }))
);
const pageNum = computed(() => props.accountheads.current_page);
const totalPages = computed(() => props.accountheads.last_page);

/* ---------------- Filters / list ---------------- */

const filter = ref(props.filters.search || '');
const filterType = ref(props.filters.type || '');
const sortBy = ref(props.filters.sortBy || 'id');
const sortDir = ref(props.filters.sortDir || 'desc');
const perPage = ref(props.filters.perPage || 20);
const perPageOptions = [20, 50, 100, 200, 500];
let filterTimeout = null;

function currentFilters(pageNumber) {
    return {
        page: pageNumber,
        search: filter.value,
        type: filterType.value,
        sortBy: sortBy.value,
        sortDir: sortDir.value,
        perPage: perPage.value,
    };
}

function load(pageNumber = 1) {
    router.get('/accounthead', currentFilters(pageNumber), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['accountheads', 'filters'],
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

/* ---------------- Form ---------------- */

function resetForm() {
    form.id = '';
    form.name = '';
    form.type = 'expense';
    onProgress.value = false;
}

async function saveData() {
    if (onProgress.value) return;
    const url = form.id != '' ? '/update-accounthead' : '/accounthead';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { id: form.id, name: form.name, type: form.type });
        toast.success(res.data.message);
        resetForm();
        load(pageNum.value);
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
    form.id = row.id;
    form.name = row.name;
    form.type = row.type;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-accounthead', { id });
    if (res.data.status) {
        toast.success(res.data.message);
        load(pageNum.value);
    }
}

/* ---------------- Print / Export ---------------- */

function print() {
    const rowsHtml = rows.value.length
        ? rows.value
              .map(
                  (item) => `
                <tr>
                    <td>${item.sl}</td>
                    <td>${item.name ?? ''}</td>
                    <td style="text-transform:capitalize;">${item.type ?? ''}</td>
                </tr>`
              )
              .join('')
        : `<tr><td colspan="3" style="text-align:center;">Not Found Data</td></tr>`;

    const bodyHtml = `
        <table>
            <thead>
                <tr>
                    <th>Sl</th><th>Name</th><th>Type</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;

    printDocument('Account Head List (current page)', bodyHtml, page.props.company);
}

function exportExcel() {
    const params = new URLSearchParams();
    const f = currentFilters(1);
    Object.entries(f).forEach(([key, value]) => {
        if (key !== 'page' && value !== '' && value != null) params.append(key, value);
    });
    window.location.href = `/accounthead/export-excel?${params.toString()}`;
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

const DEMO_COLUMNS = ['name', 'type'];
const DEMO_ROWS = [
    ['Office Rent', 'expense'],
    ['Consultancy Income', 'income'],
];

function downloadDemoTemplate() {
    const worksheet = XLSX.utils.aoa_to_sheet([DEMO_COLUMNS, ...DEMO_ROWS]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'AccountHeads');
    XLSX.writeFile(workbook, 'account-head-import-demo.xlsx');
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
            const res = await axios.post('/accounthead/import-batch', { rows: batches[i] });
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
        toast.success(`${importSummary.value.inserted} account head(s) imported successfully.`);
        load(1);
    } else {
        toast.error('No account heads were imported.');
    }
}
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2.5">
                <h3 class="text-sm font-semibold text-white">Account Head Entry</h3>
            </div>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 p-4 md:grid-cols-12">
                <div class="md:col-span-6">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                    <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-4">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                    <div class="flex h-[34px] items-center gap-4 text-sm">
                        <label class="flex items-center gap-1.5"><input type="radio" value="expense" v-model="form.type" /> Expense</label>
                        <label class="flex items-center gap-1.5"><input type="radio" value="income" v-model="form.type" /> Income</label>
                    </div>
                </div>
                <div class="flex items-end justify-end gap-2 md:col-span-2">
                    <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                    <button type="submit" :disabled="onProgress" class="rounded-md bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-1.5 text-sm font-medium text-white shadow-sm disabled:opacity-50">
                        {{ form.id == '' ? 'Save' : 'Update' }}
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-56">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input type="text" v-model="filter" @input="onFilterInput" placeholder="Name..." class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                        <select v-model="filterType" @change="load(1)" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option value="expense">Expense</option>
                            <option value="income">Income</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Show</label>
                        <select v-model.number="perPage" @change="onPerPageChange" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="openImportPanel" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                        <i class="bi bi-upload"></i> Import
                    </button>
                    <button type="button" @click="exportExcel" title="Export Excel" class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-500 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" @click="print" title="Print current page" class="text-slate-500 hover:text-brand-500">
                        <i class="bi bi-printer text-lg"></i>
                    </button>
                </div>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Sl</th>
                            <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('name')">
                                Name <i :class="sortBy === 'name' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-brand-600" @click="sortColumn('type')">
                                Type <i :class="sortBy === 'type' ? (sortDir === 'asc' ? 'bi bi-caret-up-fill' : 'bi bi-caret-down-fill') : 'bi bi-arrow-down-up text-slate-300'"></i>
                            </th>
                            <th class="px-2 py-2 font-medium">Added By</th>
                            <th class="px-2 py-2 font-medium">Updated By</th>
                            <th class="px-2 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.sl }}</td>
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5 capitalize">{{ row.type }}</td>
                            <td class="px-2 py-1.5 text-slate-500">{{ row.ad_user?.username }}</td>
                            <td class="px-2 py-1.5 text-slate-500">{{ row.up_user?.username }}</td>
                            <td class="px-2 py-1.5">
                                <div class="flex justify-end gap-3">
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="6" class="px-2 py-6 text-center text-slate-400">Not Found Data</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="pageNum" :total-pages="totalPages" @change="changePage" />
        </div>

        <!-- Import offcanvas -->
        <Teleport to="body">
            <div v-if="showImportPanel" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-slate-900/50" @click="closeImportPanel"></div>
                <div class="relative flex h-full w-full max-w-3xl flex-col bg-white shadow-2xl animate-slide-in">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <h2 class="text-base font-bold text-slate-800">Import Account Heads</h2>
                        <button type="button" @click="closeImportPanel" :disabled="importing" class="text-slate-400 hover:text-slate-600 disabled:opacity-40">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <template v-if="!importDone">
                            <div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
                                <div class="text-sm text-brand-800">
                                    <i class="bi bi-info-circle"></i>
                                    <span class="font-semibold">The first row must be the column names</span> (header row) — every row after it is treated as an account head.
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
                                    placeholder="name&#9;type&#10;Office Rent&#9;expense"
                                    class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                                ></textarea>
                            </div>
                            <div v-else>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Select a CSV or Excel file — <span class="font-semibold">the first row must be the column names</span></label>
                                <input type="file" accept=".csv,.xlsx,.xls" @change="onFileChange" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                <p v-if="fileName" class="mt-1 text-xs text-slate-500"><i class="bi bi-file-earmark-check"></i> {{ fileName }} — {{ fileRows.length }} row(s)</p>
                            </div>

                            <p class="mt-3 text-xs text-slate-500">
                                Required columns: <span class="font-semibold">name, type</span>. Type must be <span class="font-semibold">expense</span> or <span class="font-semibold">income</span>.
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
