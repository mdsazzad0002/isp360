<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import ConnectionPanel from '../../Components/Isp/ConnectionPanel.vue';
import ConnectionFormModal from '../../Components/Isp/ConnectionFormModal.vue';
import SyncBadge from '../../Components/Isp/SyncBadge.vue';
import TerminalOffcanvas from '../../Components/Isp/TerminalOffcanvas.vue';
import ConnectionPayOffcanvas from '../../Components/Isp/ConnectionPayOffcanvas.vue';
import { money, fmtDate, label, useApiError, expiryClass, fmtDateTime } from '../../lib/isp';
import { useToast } from '../../lib/toast';

defineOptions({ layout: AppLayout });
const props = defineProps({ canAct: Boolean, canSecret: Boolean, canPay: Boolean });
const can = { connection: true, connectionAction: props.canAct, connectionSecret: props.canSecret };

const rows = ref([]);
const total = ref(0);
const page = ref(1);
const lastPage = ref(1);
const packages = ref([]);
const areas = ref([]);
const filter = reactive({ search: '', status: '', syncStatus: '', package: null, area: null });
const syncCounts = ref({});
const verifying = ref(false);
const terminal = reactive({ show: false, row: null });
// pay the unpaid bill and/or extra months without leaving the list
const pay = reactive({ show: false, id: null });
function openPay(row) {
    Object.assign(pay, { show: true, id: row.id });
}
const toast = useToast();
const showError = useApiError();
// active in billing but not confirmed on the router
const unconfirmed = () => ['pending', 'failed', 'mismatch'].reduce((n, k) => n + Number(syncCounts.value[k] || 0), 0);

function showUnconfirmed(kind) {
    Object.assign(filter, { status: 'active', syncStatus: kind });
    reload();
}
// Live check of the rows on this page against the router (read-only).
async function verifyPage() {
    const ids = rows.value.filter((r) => ['pppoe', 'hotspot'].includes(r.connection_type)).map((r) => r.id);
    if (!ids.length) return toast.error('Nothing on this page to verify');
    verifying.value = true;
    try {
        const res = await axios.post('/isp/connection-verify', { ids });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    } finally {
        verifying.value = false;
    }
}
const panel = reactive({ show: false, id: null });
const formModal = reactive({ show: false, connection: null });
let timer = null;

function load() {
    axios
        .post('/isp/get-connections', { page: page.value, per_page: 20, search: filter.search, status: filter.status, syncStatus: filter.syncStatus, packageId: filter.package?.id, areaId: filter.area?.id })
        .then((res) => {
            rows.value = res.data.data;
            total.value = res.data.total;
            lastPage.value = res.data.last_page;
            syncCounts.value = res.data.syncCounts || {};
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
function open(row) {
    Object.assign(panel, { show: true, id: row.id });
}
function edit(conn) {
    panel.show = false;
    Object.assign(formModal, { show: true, connection: conn });
}

onMounted(() => {
    load();
    axios.post('/isp/get-packages').then((r) => (packages.value = r.data));
    axios.post('/get-area').then((r) => (areas.value = r.data));
});
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input v-model="filter.search" @input="onSearch" placeholder="Code, PPPoE/hotspot user, IP, customer..." class="w-64 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                        <select v-model="filter.status" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option v-for="s in ['pending', 'active', 'suspended', 'inactive', 'terminated']" :key="s" :value="s">{{ label(s) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Router sync</label>
                        <select v-model="filter.syncStatus" @change="reload" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All</option>
                            <option value="synced">Synced</option>
                            <option value="pending">Sync pending</option>
                            <option value="failed">Sync failed</option>
                            <option value="mismatch">Router mismatch</option>
                            <option value="not_managed">Not managed</option>
                        </select>
                    </div>
                    <div class="w-52">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Package</label>
                        <SearchSelect :options="packages" v-model="filter.package" label="name" placeholder="All packages" @update:model-value="reload" />
                    </div>
                    <div class="w-52">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                        <SearchSelect :options="areas" v-model="filter.area" label="name" placeholder="All areas" @update:model-value="reload" />
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="button" :disabled="verifying" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50" title="Compare the connections on this page with the router (read-only)" @click="verifyPage">
                        <i class="bi bi-search"></i> {{ verifying ? 'Verifying…' : 'Verify this page' }}
                    </button>
                    <button type="button" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600" @click="Object.assign(formModal, { show: true, connection: null })">
                        <i class="bi bi-plus-circle"></i> New connection
                    </button>
                </div>
            </div>
            <div v-if="unconfirmed()" class="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><b>{{ unconfirmed() }}</b> active connection(s) are not confirmed on the router:</span>
                <button v-for="[k, text] in [['failed', 'sync failed'], ['mismatch', 'router mismatch'], ['pending', 'sync pending']]" v-show="syncCounts[k]" :key="k" type="button" class="rounded-full border border-red-300 bg-white px-2 py-0.5 text-xs hover:bg-red-100" @click="showUnconfirmed(k)">
                    {{ syncCounts[k] }} {{ text }}
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Code</th>
                            <th class="px-2 py-2 font-medium">Customer</th>
                            <th class="px-2 py-2 font-medium">Area / Box</th>
                            <th class="px-2 py-2 font-medium">Package</th>
                            <th class="px-2 py-2 font-medium">Type / User / IP</th>
                            <th class="px-2 py-2 font-medium">Activated</th>
                            <th class="px-2 py-2 font-medium">Expire date</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 font-medium">Router sync</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-2 font-medium text-brand-600">{{ row.code }}</td>
                            <td class="px-2 py-2">
                                <Link :href="`/isp/customer/${row.customer_id}`" class="hover:underline">{{ row.customer?.name }}</Link>
                                <div class="text-xs text-slate-400">{{ row.customer?.code }} · {{ row.customer?.phone }}</div>
                            </td>
                            <td class="px-2 py-2">{{ row.customer?.area?.name || '—' }}<div class="text-xs text-slate-400">{{ row.box?.name }}</div></td>
                            <td class="px-2 py-2">{{ row.package?.name }}<div class="text-xs text-slate-400">Tk {{ money(row.package?.price) }}<span v-if="Number(row.discount)"> − {{ money(row.discount) }}</span></div></td>
                            <td class="px-2 py-2">{{ label(row.connection_type) }}<div class="text-xs text-slate-400">{{ row.pppoe_username || row.static_ip || '—' }}</div></td>
                            <td class="px-2 py-2">{{ fmtDate(row.activation_date) || '—' }}</td>
                            <td class="px-2 py-2">
                                <span :class="expiryClass(row.expire_at)">{{ fmtDateTime(row.expire_at) || 'Unpaid' }}</span>
                                <div v-if="Number(row.open_due) > 0" class="mt-0.5 text-xs text-red-600">Tk {{ money(row.open_due) }} due</div>
                            </td>
                            <td class="px-2 py-2"><StatusBadge :status="row.status" /></td>
                            <td class="px-2 py-2"><SyncBadge :connection="row" /></td>
                            <td class="px-2 py-2">
                                <div class="flex justify-end gap-1 whitespace-nowrap">
                                    <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-100" title="Connection details" @click="open(row)"><i class="bi bi-eye"></i> Details</button>
                                    <button v-if="canPay && !['terminated', 'inactive'].includes(row.status)" type="button" class="rounded bg-emerald-600 px-2 py-0.5 text-xs font-medium text-white hover:bg-emerald-700" :title="Number(row.open_due) > 0 ? `Due Tk ${money(row.open_due)}` : 'Pay extra months in advance'" @click="openPay(row)"><i class="bi bi-cash-coin"></i> Pay</button>
                                    <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-600 hover:border-slate-800 hover:bg-slate-900 hover:text-emerald-400" title="Open terminal" @click="Object.assign(terminal, { show: true, row })"><i class="bi bi-terminal"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="10" class="px-2 py-6 text-center text-slate-400">No connections found</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-slate-500">{{ total }} connections</span>
                <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
            </div>
        </div>

        <TerminalOffcanvas v-if="terminal.row" v-model="terminal.show" :connections="[terminal.row]" :connection-id="terminal.row.id" @changed="load" />
        <ConnectionPanel :show="panel.show" :connection-id="panel.id" :can="can" @close="panel.show = false" @changed="load" @edit="edit" />
        <ConnectionPayOffcanvas v-model="pay.show" :connection-id="pay.id" @saved="load" />
        <ConnectionFormModal :show="formModal.show" :connection="formModal.connection" @close="formModal.show = false" @saved="load" />
    </div>
</template>
