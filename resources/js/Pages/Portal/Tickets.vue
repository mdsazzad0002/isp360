<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import Modal from '../../Components/Modal.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import TicketThread from '../../Components/Isp/TicketThread.vue';
import { useToast } from '../../lib/toast';
import { fmtDate, label, useApiError, PRIORITY_CLASSES } from '../../lib/isp';

// Support tickets in the customer portal and the reseller portal.
const props = defineProps({
    portal: { type: String, required: true }, // 'customer' | 'reseller'
    me: { type: Object, required: true },
    openId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] }, // reseller: their customers
    connections: { type: Array, default: () => [] }, // customer: their connections
});
const toast = useToast();
const showError = useApiError();

const isReseller = computed(() => props.portal === 'reseller');
const base = computed(() => (isReseller.value ? '/reseller' : '/customer-portal'));
const logoutUrl = computed(() => `${base.value}/logout`);

const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const filter = reactive({ status: 'active', mine: '', search: '' });
const selected = ref(props.openId);
let timer = null;

function load() {
    axios.post(`${base.value}/get-tickets`, { page: page.value, ...filter }).then((res) => {
        rows.value = res.data.data;
        lastPage.value = res.data.last_page;
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
// "Needs my reply": the other side answered last.
function awaitingMe(row) {
    if (['resolved', 'closed'].includes(row.status)) return false;
    return row.last_reply_by_type && row.last_reply_by_type !== props.portal;
}

// New ticket
function blank() {
    return { about: 'me', customer_id: '', connection_id: '', subject: '', category: props.categories[0] || 'other', priority: 'normal', message: '', file: null, saving: false };
}
const form = reactive({ show: false, ...blank() });
const customerSearch = ref('');
const customerOptions = computed(() => {
    const t = customerSearch.value.trim().toLowerCase();
    return (t ? props.customers.filter((c) => `${c.name} ${c.code} ${c.phone}`.toLowerCase().includes(t)) : props.customers).slice(0, 200);
});
function openNew() {
    Object.assign(form, blank(), { show: true });
    customerSearch.value = '';
}
async function submit() {
    form.saving = true;
    try {
        const fd = new FormData();
        for (const k of ['subject', 'category', 'priority', 'message']) fd.append(k, form[k]);
        if (isReseller.value && form.about === 'customer' && form.customer_id) fd.append('customer_id', form.customer_id);
        if (!isReseller.value && form.connection_id) fd.append('connection_id', form.connection_id);
        if (form.file) fd.append('attachment', form.file);
        const res = await axios.post(`${base.value}/ticket`, fd);
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
    <PortalLayout :user-name="me.name" :logout-url="logoutUrl">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">Support Tickets</h1>
            <button type="button" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white" @click="openNew"><i class="bi bi-plus-lg"></i> New ticket</button>
        </div>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white shadow-sm" :class="selected ? 'hidden lg:col-span-2 lg:block' : 'lg:col-span-5'">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
                    <select v-model="filter.status" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload">
                        <option value="active">Open tickets</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                        <option value="">All</option>
                    </select>
                    <select v-if="isReseller" v-model="filter.mine" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="reload">
                        <option value="">Mine + my customers'</option>
                        <option value="own">My tickets to the company</option>
                        <option value="customers">My customers' tickets</option>
                    </select>
                    <input v-model="filter.search" placeholder="Search…" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" @input="onSearch" />
                </div>
                <ul>
                    <li
                        v-for="row in rows"
                        :key="row.id"
                        class="cursor-pointer border-b border-slate-100 px-3 py-2.5 hover:bg-slate-50"
                        :class="selected === row.id ? 'bg-brand-50' : ''"
                        @click="selected = row.id"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-slate-800">
                                    <span v-if="awaitingMe(row)" class="mr-1 inline-block h-2 w-2 rounded-full bg-red-500" title="New reply"></span>{{ row.subject }}
                                </div>
                                <div class="text-xs text-slate-400">
                                    {{ row.ticket_no }} · {{ label(row.category) }}
                                    <span v-if="isReseller && row.customer"> · {{ row.customer.name }}</span>
                                    <span v-else-if="isReseller"> · to company</span>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <StatusBadge :status="row.status" />
                                <div class="mt-0.5 text-[11px]" :class="PRIORITY_CLASSES[row.priority]">{{ fmtDate(row.last_reply_at || row.created_at) }}</div>
                            </div>
                        </div>
                    </li>
                    <li v-if="!rows.length" class="px-3 py-8 text-center text-sm text-slate-400">No tickets</li>
                </ul>
                <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
            </div>

            <div v-if="selected" class="rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-3">
                <TicketThread :ticket-id="selected" :base="base" :viewer="portal" @changed="load" @close="selected = null" />
            </div>
        </div>

        <Modal :show="form.show" max-width="max-w-lg" @close="form.show = false">
            <form class="space-y-3 p-5 text-sm" @submit.prevent="submit">
                <h2 class="text-base font-semibold text-slate-800">New support ticket</h2>
                <div v-if="isReseller" class="flex gap-4">
                    <label class="flex items-center gap-1"><input v-model="form.about" type="radio" value="me" /> For me (to the company)</label>
                    <label class="flex items-center gap-1"><input v-model="form.about" type="radio" value="customer" /> For one of my customers</label>
                </div>
                <div v-if="isReseller && form.about === 'customer'">
                    <input v-model="customerSearch" placeholder="Search customer" class="mb-1 w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    <select v-model="form.customer_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                        <option value="" disabled>— Select customer —</option>
                        <option v-for="c in customerOptions" :key="c.id" :value="c.id">{{ c.name }} · {{ c.code }} · {{ c.phone }}</option>
                    </select>
                </div>
                <div v-if="!isReseller && connections.length">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Connection</label>
                    <select v-model="form.connection_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                        <option value="">— Not about one connection —</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.code }} {{ c.pppoe_username ? `(${c.pppoe_username})` : '' }} · {{ label(c.status) }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Subject</label>
                    <input v-model="form.subject" required maxlength="200" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Category</label>
                        <select v-model="form.category" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option v-for="c in categories" :key="c" :value="c">{{ label(c) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Priority</label>
                        <select v-model="form.priority" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Describe the problem</label>
                    <textarea v-model="form.message" required rows="5" maxlength="5000" class="w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Screenshot / file (optional, max 3 MB)</label>
                    <input type="file" accept="image/*,.pdf" class="text-xs" @change="form.file = $event.target.files[0] || null" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="form.show = false">Cancel</button>
                    <button type="submit" :disabled="form.saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Open ticket</button>
                </div>
            </form>
        </Modal>
    </PortalLayout>
</template>
