<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import InvoiceDetailModal from '../../Components/Isp/InvoiceDetailModal.vue';
import ManualInvoiceModal from '../../Components/Isp/ManualInvoiceModal.vue';
import ReceivePaymentForm from '../../Components/Isp/ReceivePaymentForm.vue';
import { money, fmtDate, label, useApiError, fmtDateTime } from '../../lib/isp';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';

defineOptions({ layout: AppLayout });
const props = defineProps({ can: { type: Object, default: () => ({}) } });
const toast = useToast();
const showError = useApiError();

const rows = ref([]);
const totals = ref({});
const page = ref(1);
const lastPage = ref(1);
const areas = ref([]);
const filter = reactive({ search: '', status: '', dateFrom: '', dateTo: '', area: null });
const detail = reactive({ show: false, id: null });
const manual = reactive({ show: false, draft: null });
const receive = reactive({ show: false, customerId: null, invoiceId: null });
const generating = ref(false);
let timer = null;

function load() {
    axios
        .post('/isp/get-invoices', { page: page.value, per_page: 20, search: filter.search, status: filter.status, dateFrom: filter.dateFrom, dateTo: filter.dateTo, areaId: filter.area?.id })
        .then((res) => {
            rows.value = res.data.page.data;
            lastPage.value = res.data.page.last_page;
            totals.value = res.data.totals;
        });
}
function reload() {
    page.value = 1;
    load();
}
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(reload, 300);
}

async function generate() {
    if (!(await confirmDialog({ title: 'Issue renewal invoices now?', text: 'Every connection whose paid time ends soon (or has ended) and has no open bill gets one. This also runs automatically every minute, so running it again is safe.', confirmButtonText: 'Generate' }))) return;
    generating.value = true;
    try {
        const res = await axios.post('/isp/invoice-generate');
        toast.success(res.data.message);
        (res.data.stats?.errors || []).slice(0, 5).forEach((e) => toast.error(e));
        reload();
    } catch (err) {
        showError(err);
    } finally {
        generating.value = false;
    }
}

function editDraft(inv) {
    detail.show = false;
    Object.assign(manual, { show: true, draft: inv });
}
function receiveFor(inv) {
    detail.show = false;
    Object.assign(receive, { show: true, customerId: inv.customer_id, invoiceId: inv.id });
}

onMounted(() => {
    load();
    axios.post('/get-area').then((r) => (areas.value = r.data));
});
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Invoices</div><div class="text-lg font-semibold text-slate-800">{{ totals.count ?? 0 }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Billed</div><div class="text-lg font-semibold text-slate-800">{{ money(totals.total) }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Paid</div><div class="text-lg font-semibold text-emerald-700">{{ money(totals.paid) }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"><div class="text-xs text-slate-500">Due</div><div class="text-lg font-semibold text-red-600">{{ money(totals.due) }}</div></div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input v-model="filter.search" @input="onSearch" placeholder="Invoice no, customer, phone" class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                        <select v-model="filter.status" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option value="open">Open (unpaid)</option>
                            <option v-for="s in ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'cancelled']" :key="s" :value="s">{{ label(s) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                        <input v-model="filter.dateFrom" type="date" @change="reload" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                        <input v-model="filter.dateTo" type="date" @change="reload" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                    </div>
                    <div class="w-48">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                        <SearchSelect :options="areas" v-model="filter.area" label="name" placeholder="All areas" @update:model-value="reload" />
                    </div>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <template v-if="can.generate">
                        <button type="button" :disabled="generating" class="rounded-md border border-brand-500 px-3 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50 disabled:opacity-50" @click="generate">
                            <i class="bi bi-lightning-charge"></i> {{ generating ? 'Generating...' : 'Generate renewals' }}
                        </button>
                    </template>
                    <button v-if="can.create" type="button" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-600" @click="Object.assign(manual, { show: true, draft: null })">
                        <i class="bi bi-plus-circle"></i> Manual invoice
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Invoice</th>
                            <th class="px-2 py-2 font-medium">Customer</th>
                            <th class="px-2 py-2 font-medium">Period</th>
                            <th class="px-2 py-2 font-medium">Date / Due</th>
                            <th class="px-2 py-2 text-end font-medium">Total</th>
                            <th class="px-2 py-2 text-end font-medium">Paid</th>
                            <th class="px-2 py-2 text-end font-medium">Due</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="Object.assign(detail, { show: true, id: row.id })">
                            <td class="px-2 py-2 font-medium text-brand-600">{{ row.invoice_no }}<div class="text-xs font-normal text-slate-400">{{ row.connection?.code || 'Customer level' }}</div></td>
                            <td class="px-2 py-2">
                                <Link :href="`/isp/customer/${row.customer_id}`" class="hover:underline" @click.stop>{{ row.customer?.name }}</Link>
                                <div class="text-xs text-slate-400">{{ row.customer?.code }} · {{ row.customer?.area?.name }}</div>
                            </td>
                            <td class="px-2 py-2 text-xs">{{ row.period_start ? `${fmtDateTime(row.period_start)} – ${fmtDateTime(row.period_end)}` : row.service_months ? 'Starts when paid' : '—' }}</td>
                            <td class="px-2 py-2">{{ fmtDate(row.invoice_date) }}<div class="text-xs text-slate-400">due {{ fmtDate(row.due_date) }}</div></td>
                            <td class="px-2 py-2 text-end">{{ money(row.total) }}</td>
                            <td class="px-2 py-2 text-end text-emerald-700">{{ money(row.paid) }}</td>
                            <td class="px-2 py-2 text-end font-medium" :class="Number(row.due) > 0 ? 'text-red-600' : ''">{{ money(row.due) }}</td>
                            <td class="px-2 py-2"><StatusBadge :status="row.status" /></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-2 py-6 text-center text-slate-400">No invoices found</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
        </div>

        <InvoiceDetailModal :show="detail.show" :invoice-id="detail.id" :can="can" @close="detail.show = false" @changed="load" @edit-draft="editDraft" @receive="receiveFor" />
        <ManualInvoiceModal :show="manual.show" :draft="manual.draft" @close="manual.show = false" @saved="load" />
        <Modal :show="receive.show" max-width="max-w-5xl" @close="receive.show = false">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-base font-semibold text-slate-800">Receive payment</h2>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="receive.show = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="p-4">
                <ReceivePaymentForm v-if="receive.show" :customer-id="receive.customerId" :invoice-id="receive.invoiceId" @saved="receive.show = false; load()" />
            </div>
        </Modal>
    </div>
</template>
