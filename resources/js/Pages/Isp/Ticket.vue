<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import TicketThread from '../../Components/Isp/TicketThread.vue';
import CustomerPicker from '../../Components/Isp/CustomerPicker.vue';
import { useToast } from '../../lib/toast';
import { fmtDate, label, useApiError, PRIORITY_CLASSES } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({
    openId: { type: Number, default: null },
    staff: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});
const toast = useToast();
const showError = useApiError();

const rows = ref([]);
const counts = ref({});
const needsReply = ref(0);
const page = ref(1);
const lastPage = ref(1);
const filter = reactive({ status: 'active', needsReply: false, priority: '', from: '', assigned: '', search: '' });
const selected = ref(props.openId);
let timer = null;

function load() {
    axios.post('/isp/get-tickets', { page: page.value, ...filter }).then((res) => {
        rows.value = res.data.page.data;
        lastPage.value = res.data.page.last_page;
        counts.value = res.data.counts;
        needsReply.value = res.data.needsReply;
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
const awaitingUs = (row) => !['resolved', 'closed'].includes(row.status) && row.last_reply_by_type !== 'admin';
const opener = (row) => (row.opened_by_type === 'admin' ? 'Company' : label(row.opened_by_type));

// New ticket on behalf of a customer or reseller
const resellers = ref([]);
function blank() {
    return { about: 'customer', customer: null, reseller_id: '', subject: '', category: 'connection', priority: 'normal', message: '', file: null, saving: false };
}
const form = reactive({ show: false, ...blank() });
function openNew() {
    Object.assign(form, blank(), { show: true });
    if (!resellers.value.length) axios.post('/get-reseller').then((r) => (resellers.value = r.data));
}
async function submit() {
    form.saving = true;
    try {
        const fd = new FormData();
        for (const k of ['subject', 'category', 'priority', 'message']) fd.append(k, form[k]);
        if (form.about === 'customer' && form.customer) fd.append('customer_id', form.customer.id);
        if (form.about === 'reseller' && form.reseller_id) fd.append('reseller_id', form.reseller_id);
        if (form.file) fd.append('attachment', form.file);
        const res = await axios.post('/isp/ticket', fd);
        toast.success(res.data.message);
        form.show = false;
        selected.value = res.data.id;
        reload();
    } catch (err) {
        showError(err);
    } finally {
        form.saving = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-base font-semibold text-slate-800">Support Tickets</h1>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" class="rounded-full border px-3 py-1" :class="filter.needsReply ? 'border-red-300 bg-red-50 text-red-700' : 'border-slate-300 text-slate-600'" @click="filter.needsReply = !filter.needsReply; reload()">
                    Needs reply <b>{{ needsReply }}</b>
                </button>
                <span v-for="s in ['open', 'in_progress', 'waiting', 'resolved']" :key="s" class="text-slate-500">{{ label(s) }}: <b>{{ counts[s] || 0 }}</b></span>
                <button type="button" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white" @click="openNew"><i class="bi bi-plus-lg"></i> New ticket</button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 xl:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white shadow-sm" :class="selected ? 'xl:col-span-2' : 'xl:col-span-5'">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
                    <select v-model="filter.status" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload">
                        <option value="active">Not resolved</option>
                        <option v-for="s in ['open', 'in_progress', 'waiting', 'resolved', 'closed']" :key="s" :value="s">{{ label(s) }}</option>
                        <option value="">All</option>
                    </select>
                    <select v-model="filter.from" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload">
                        <option value="">Company + reseller</option>
                        <option value="company">Company customers</option>
                        <option value="reseller">Reseller & reseller customers</option>
                    </select>
                    <select v-model="filter.priority" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload">
                        <option value="">Any priority</option>
                        <option v-for="p in ['urgent', 'high', 'normal', 'low']" :key="p" :value="p">{{ label(p) }}</option>
                    </select>
                    <select v-model="filter.assigned" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload">
                        <option value="">Anyone</option>
                        <option value="me">Assigned to me</option>
                    </select>
                    <input v-model="filter.search" placeholder="Ticket, subject, customer, reseller" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" @input="onSearch" />
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                                <th class="px-3 py-2 font-medium">Ticket</th>
                                <th class="px-3 py-2 font-medium">From</th>
                                <th v-if="!selected" class="px-3 py-2 font-medium">Assigned</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium">Last reply</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id" class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" :class="selected === row.id ? 'bg-brand-50' : ''" @click="selected = row.id">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-slate-800"><span v-if="awaitingUs(row)" class="me-1 inline-block h-2 w-2 rounded-full bg-red-500" title="Waiting for company reply"></span>{{ row.subject }}</div>
                                    <div class="text-xs text-slate-400">{{ row.ticket_no }} · {{ label(row.category) }} · <span :class="PRIORITY_CLASSES[row.priority]">{{ label(row.priority) }}</span></div>
                                </td>
                                <td class="px-3 py-2">
                                    <template v-if="row.customer">{{ row.customer.name }}<div class="text-xs text-slate-400">{{ row.customer.phone }}</div></template>
                                    <template v-else-if="row.reseller">{{ row.reseller.name }}<div class="text-xs text-slate-400">Reseller</div></template>
                                    <div v-if="row.customer && row.reseller" class="text-xs text-amber-700">via {{ row.reseller.name }}</div>
                                    <div class="text-[11px] text-slate-400">opened by {{ opener(row) }}</div>
                                </td>
                                <td v-if="!selected" class="px-3 py-2 text-xs text-slate-500">{{ row.assignee?.name || '—' }}</td>
                                <td class="px-3 py-2"><StatusBadge :status="row.status" /></td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ fmtDate(row.last_reply_at) }}<div>{{ label(row.last_reply_by_type) }}</div></td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="5" class="px-3 py-8 text-center text-slate-400">No tickets</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
            </div>

            <div v-if="selected" class="rounded-lg border border-slate-200 bg-white shadow-sm xl:col-span-3">
                <TicketThread :ticket-id="selected" base="/isp" viewer="admin" :staff="staff" :categories="categories" @changed="load" @close="selected = null" />
            </div>
        </div>

        <Modal :show="form.show" max-width="max-w-lg" @close="form.show = false">
            <form class="space-y-3 p-5 text-sm" @submit.prevent="submit">
                <h2 class="text-base font-semibold text-slate-800">New ticket</h2>
                <div class="flex gap-4">
                    <label class="flex items-center gap-1"><input v-model="form.about" type="radio" value="customer" /> Customer</label>
                    <label class="flex items-center gap-1"><input v-model="form.about" type="radio" value="reseller" /> Reseller</label>
                </div>
                <CustomerPicker v-if="form.about === 'customer'" v-model="form.customer" />
                <select v-else v-model="form.reseller_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                    <option value="" disabled>— Select reseller —</option>
                    <option v-for="r in resellers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.code }})</option>
                </select>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Subject</label>
                    <input v-model="form.subject" required maxlength="200" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <select v-model="form.category" class="rounded-md border border-slate-300 px-3 py-1.5">
                        <option v-for="c in categories" :key="c" :value="c">{{ label(c) }}</option>
                    </select>
                    <select v-model="form.priority" class="rounded-md border border-slate-300 px-3 py-1.5">
                        <option v-for="p in ['low', 'normal', 'high', 'urgent']" :key="p" :value="p">{{ label(p) }} priority</option>
                    </select>
                </div>
                <textarea v-model="form.message" required rows="5" placeholder="Message" class="w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                <input type="file" accept="image/*,.pdf" class="text-xs" @change="form.file = $event.target.files[0] || null" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="form.show = false">Cancel</button>
                    <button type="submit" :disabled="form.saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Open ticket</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
