<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { useToast } from '../../lib/toast';
import { money, fmtDate, label, useApiError, GATEWAY_STYLES, fmtMoney, cur, moneyStep } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({ canReview: Boolean });
const toast = useToast();
const showError = useApiError();

const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const pending = ref({ count: 0, amount: 0 });
const filter = reactive({ search: '', status: 'pending_review', gateway: '', dateFrom: '', dateTo: '' });
const review = reactive({ show: false, row: null, action: 'approve', amount: '', note: '', reason: '', busy: false });
let timer = null;

const STATUSES = ['pending_review', 'completed', 'initiated', 'failed', 'cancelled', 'rejected'];

function load() {
    axios.post('/isp/get-online-payments', { page: page.value, per_page: 20, ...filter }).then((res) => {
        rows.value = res.data.page.data;
        lastPage.value = res.data.page.last_page;
        total.value = res.data.page.total;
        pending.value = res.data.pending;
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

function openReview(row, action) {
    Object.assign(review, { show: true, row, action, amount: Number(row.amount), note: '', reason: '', busy: false });
}

async function submitReview() {
    review.busy = true;
    try {
        const res = review.action === 'approve'
            ? await axios.post('/isp/online-payment-approve', { id: review.row.id, amount: review.amount, note: review.note })
            : await axios.post('/isp/online-payment-reject', { id: review.row.id, reason: review.reason });
        toast.success(res.data.message);
        review.show = false;
        load();
    } catch (err) {
        showError(err);
    } finally {
        review.busy = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="p-4">
        <div
            v-if="pending.count > 0"
            class="mb-3 flex cursor-pointer items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            @click="filter.status = 'pending_review'; reload()"
        >
            <i class="bi bi-hourglass-split text-lg"></i>
            <span><strong>{{ pending.count }}</strong> payment(s) of {{ fmtMoney(pending.amount) }} are waiting for verification. Check each Transaction ID in the wallet app before approving.</span>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                    <input v-model="filter.search" placeholder="Ref, TrxID, sender, customer..." class="w-60 rounded-md border border-slate-300 px-3 py-1.5 text-sm" @input="onSearch" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                    <select v-model="filter.status" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reload">
                        <option value="">All</option>
                        <option v-for="s in STATUSES" :key="s" :value="s">{{ label(s) }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                    <select v-model="filter.gateway" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reload">
                        <option value="">All</option>
                        <option v-for="(g, key) in GATEWAY_STYLES" :key="key" :value="key">{{ g.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                    <input v-model="filter.dateFrom" type="date" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reload" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <input v-model="filter.dateTo" type="date" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="reload" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Date</th>
                            <th class="px-2 py-2 font-medium">Customer</th>
                            <th class="px-2 py-2 font-medium">Method</th>
                            <th class="px-2 py-2 font-medium">TrxID / Sender</th>
                            <th class="px-2 py-2 font-medium">For</th>
                            <th class="px-2 py-2 text-end font-medium">Amount</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-2">{{ fmtDate(row.created_at) }}<div class="text-xs text-slate-400">{{ row.ref }}</div></td>
                            <td class="px-2 py-2">
                                <Link :href="`/isp/customer/${row.customer_id}`" class="hover:underline">{{ row.customer?.name }}</Link>
                                <div class="text-xs text-slate-400">{{ row.customer?.code }} · {{ row.customer?.phone }}</div>
                            </td>
                            <td class="px-2 py-2">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="flex h-5 w-5 items-center justify-center rounded text-[10px] font-bold text-white" :class="GATEWAY_STYLES[row.gateway]?.badge">{{ GATEWAY_STYLES[row.gateway]?.initial }}</span>
                                    {{ GATEWAY_STYLES[row.gateway]?.label }}
                                </span>
                                <div class="text-xs text-slate-400">{{ row.mode === 'api' ? 'Automatic' : 'Manual' }}</div>
                            </td>
                            <td class="px-2 py-2 font-mono text-xs">{{ row.trx_id || '—' }}<div v-if="row.sender_number" class="text-slate-400">{{ row.sender_number }}</div></td>
                            <td class="px-2 py-2">{{ row.purpose === 'wallet' ? 'Wallet' : 'Bill' }}</td>
                            <td class="px-2 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                            <td class="px-2 py-2">
                                <StatusBadge :status="row.status" />
                                <div v-if="row.customer_payment" class="text-xs text-slate-400">Receipt {{ row.customer_payment.receipt_no }}</div>
                                <div v-else-if="row.failure_reason" class="max-w-[14rem] truncate text-xs text-slate-400" :title="row.failure_reason">{{ row.failure_reason }}</div>
                                <div v-if="row.reviewed_by" class="text-xs text-slate-400">by {{ row.reviewed_by.name }}</div>
                            </td>
                            <td class="px-2 py-2 text-end">
                                <div v-if="canReview && row.status === 'pending_review'" class="flex justify-end gap-1.5">
                                    <button type="button" class="rounded bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700" @click="openReview(row, 'approve')">Approve</button>
                                    <button type="button" class="rounded border border-red-300 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50" @click="openReview(row, 'reject')">Reject</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-2 py-6 text-center text-slate-400">No online payments found</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-slate-500">{{ total }} payments</span>
                <Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; load(); }" />
            </div>
        </div>

        <Modal :show="review.show" max-width="max-w-md" @close="review.show = false">
            <form v-if="review.row" class="p-5 text-sm" @submit.prevent="submitReview">
                <h2 class="mb-1 text-base font-semibold text-slate-800">{{ review.action === 'approve' ? 'Approve payment' : 'Reject payment' }}</h2>
                <p class="mb-4 text-slate-500">
                    {{ review.row.customer?.name }} · {{ GATEWAY_STYLES[review.row.gateway]?.label }} · TrxID <span class="font-mono">{{ review.row.trx_id }}</span>
                    <template v-if="review.row.sender_number"> from {{ review.row.sender_number }}</template>
                </p>
                <template v-if="review.action === 'approve'">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Amount actually received ({{ cur() }})</label>
                    <input v-model="review.amount" type="number" min="1" :step="moneyStep()" required class="mb-3 w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    <label class="mb-1 block text-xs font-medium text-slate-600">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input v-model="review.note" maxlength="200" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    <p class="mt-3 text-xs text-slate-500">This records a payment in the customer's ledger and applies it to open bills. It can only be undone by reversing the payment.</p>
                </template>
                <template v-else>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Reason (shown to the customer)</label>
                    <input v-model="review.reason" maxlength="200" required placeholder="e.g. Transaction ID not found" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </template>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5 text-slate-600 hover:bg-slate-50" @click="review.show = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="review.busy"
                        class="rounded-md px-4 py-1.5 font-medium text-white disabled:opacity-50"
                        :class="review.action === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'"
                    >
                        {{ review.action === 'approve' ? 'Approve' : 'Reject' }}
                    </button>
                </div>
            </form>
        </Modal>
    </div>
</template>
