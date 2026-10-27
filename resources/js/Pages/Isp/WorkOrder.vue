<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Offcanvas from '../../Components/Offcanvas.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import Pagination from '../../Components/Pagination.vue';
import CustomerPicker from '../../Components/Isp/CustomerPicker.vue';
import WorkOrderDetail from '../../Components/Isp/WorkOrderDetail.vue';
import { useToast } from '../../lib/toast';
import { useApiError, fmtDateTime, label } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({ types: Object, staff: Array, prefill: Object });
const toast = useToast();
const showError = useApiError();

const filter = reactive({ status: 'open', type: '', assigned_to: '', date: '', search: '' });
const rows = ref([]);
const counts = ref({});
const page = ref(1);
const lastPage = ref(1);

function load() {
    axios.post('/isp/get-work-orders', { ...filter, page: page.value }).then((r) => {
        rows.value = r.data.page.data;
        lastPage.value = r.data.page.last_page;
        counts.value = r.data.counts;
    });
}
const apply = () => {
    page.value = 1;
    load();
};

// ---- new work order ----
const blank = () => ({ open: false, type: 'repair', priority: 'normal', customer: null, connection: null, ticket_id: null, assigned_to: '', scheduled_at: '', address: '', notes: '' });
const create = reactive(blank());
const connectionOptions = ref([]);
const connLabel = (c) => ({ ...c, display: `${c.code} · ${c.customer?.name || ''}${c.pppoe_username ? ' · ' + c.pppoe_username : ''}` });
async function searchConnections(q, setLoading) {
    setLoading(true);
    try {
        connectionOptions.value = (await axios.post('/isp/get-connections', { search: q, per_page: 20, customerId: create.customer?.id })).data.data.map(connLabel);
    } finally {
        setLoading(false);
    }
}
async function openCreate(extra = {}) {
    Object.assign(create, blank(), { open: true }, extra);
    connectionOptions.value = create.connection ? [create.connection] : [];
}
async function save() {
    const { open, customer, connection, ...data } = create;
    try {
        toast.success((await axios.post('/isp/work-order', { ...data, customer_id: customer?.id, connection_id: connection?.id })).data.message);
        create.open = false;
        load();
    } catch (err) {
        showError(err);
    }
}

async function assign(w, userId) {
    try {
        toast.success((await axios.post('/isp/work-order-assign', { id: w.id, assigned_to: userId || null })).data.message);
        load();
    } catch (err) {
        showError(err);
        load();
    }
}
const detailId = ref(null);

onMounted(async () => {
    load();
    // opened from a connection or a ticket: start a new job for it
    if (props.prefill?.open) {
        const extra = { type: props.prefill.type || 'repair', ticket_id: props.prefill.ticket_id ? Number(props.prefill.ticket_id) : null };
        if (props.prefill.connection_id) {
            const c = (await axios.post('/isp/get-connection', { id: props.prefill.connection_id })).data;
            extra.connection = connLabel(c);
            extra.customer = c.customer ? { ...c.customer, display_name: `${c.customer.name} (${c.customer.code})` } : null;
        }
        openCreate(extra);
    }
});

const STATUS_CLASS = { new: 'bg-sky-100 text-sky-700', assigned: 'bg-indigo-100 text-indigo-700', in_progress: 'bg-amber-100 text-amber-700', done: 'bg-emerald-100 text-emerald-700', cancelled: 'bg-slate-100 text-slate-500' };
const PRIORITY_CLASS = { urgent: 'text-red-600 font-semibold', high: 'text-orange-600', normal: 'text-slate-500', low: 'text-slate-400' };
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="me-2 text-base font-semibold text-slate-800">Work Orders</h1>
            <button v-for="s in ['open', 'new', 'assigned', 'in_progress', 'done', 'cancelled']" :key="s" type="button" class="rounded-full border px-3 py-0.5 text-xs"
                :class="filter.status === s ? 'border-brand-500 bg-brand-500 text-white' : 'border-slate-300 bg-white'" @click="filter.status = s; apply()">
                {{ s === 'open' ? 'Open' : label(s) }}<span v-if="s !== 'open' && counts[s]"> ({{ counts[s] }})</span>
            </button>
            <button type="button" class="ms-auto rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white" @click="openCreate()"><i class="bi bi-plus"></i> New work order</button>
        </div>

        <div class="flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <select v-model="filter.type" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="apply"><option value="">Any type</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="filter.assigned_to" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="apply">
                <option value="">Anyone</option><option value="none">Not assigned</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <input v-model="filter.date" type="date" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="apply" />
            <input v-model="filter.search" placeholder="WO no., customer, phone, address" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @keyup.enter="apply" />
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Job</th>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">Technician</th>
                        <th class="px-3 py-2 font-medium">Scheduled</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="w in rows" :key="w.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="detailId = w.id">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ w.wo_no }} · {{ types[w.type] }}</div>
                            <div class="text-xs" :class="PRIORITY_CLASS[w.priority]">{{ w.priority !== 'normal' ? label(w.priority) + ' · ' : '' }}<span class="text-slate-500">{{ w.notes }}</span></div>
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ w.customer?.name }} <span class="text-xs text-slate-400">{{ w.customer?.phone }}</span></div>
                            <div class="text-xs text-slate-500">{{ w.address }}</div>
                        </td>
                        <td class="px-3 py-2" @click.stop>
                            <select v-if="['new', 'assigned', 'in_progress'].includes(w.status)" :value="w.assigned_to || ''" class="rounded-md border border-slate-300 px-1 py-0.5 text-xs" @change="assign(w, $event.target.value)">
                                <option value="">— assign —</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <span v-else class="text-xs">{{ w.assignee?.name || '—' }}</span>
                        </td>
                        <td class="px-3 py-2 text-xs">{{ w.scheduled_at ? fmtDateTime(w.scheduled_at) : '—' }}</td>
                        <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-xs" :class="STATUS_CLASS[w.status]">{{ label(w.status) }}</span></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="5" class="px-3 py-6 text-center text-slate-400">No work orders</td></tr>
                </tbody>
            </table>
            <div class="p-3"><Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" /></div>
        </div>

        <WorkOrderDetail :id="detailId" office @close="detailId = null" @changed="load" />

        <Offcanvas :show="create.open" width="sm:w-[520px]" @close="create.open = false">
            <form class="flex h-full flex-col" @submit.prevent="save">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">New work order<span v-if="create.ticket_id" class="text-sm font-normal text-slate-500"> (from a ticket)</span></div>
                <div class="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">Type <select v-model="create.type" :class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></label>
                        <label class="block">Priority <select v-model="create.priority" :class="input"><option v-for="p in ['low', 'normal', 'high', 'urgent']" :key="p" :value="p">{{ label(p) }}</option></select></label>
                    </div>
                    <label class="block">Customer <CustomerPicker v-model="create.customer" /></label>
                    <label class="block">Connection (optional)
                        <SearchSelect v-model="create.connection" :options="connectionOptions" label="display" placeholder="Search code, PPPoE user…" @search="searchConnections" />
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">Technician <select v-model="create.assigned_to" :class="input"><option value="">Later</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                        <label class="block">When <input v-model="create.scheduled_at" type="datetime-local" :class="input" /></label>
                    </div>
                    <label class="block">Address <input v-model="create.address" placeholder="From the customer when empty" :class="input" /></label>
                    <label class="block">What to do <textarea v-model="create.notes" rows="4" :class="input"></textarea></label>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="create.open = false">Cancel</button>
                    <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Create</button>
                </div>
            </form>
        </Offcanvas>
    </div>
</template>
