<script setup>
import { ref, reactive, computed, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { useToast } from '../../lib/toast';
import { money, fmtDate, useApiError, GATEWAY_STYLES, fmtMoney, cur, moneyStep, roundMoney } from '../../lib/isp';

const { t: i18nT } = useI18n();
const t = (key, values) => i18nT(`portal.${key}`, values ?? {});
const props = defineProps({
    customer: { type: Object, required: true },
    summary: { type: Object, default: () => ({ due: 0, wallet: 0, pending: 0 }) },
    gateways: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    result: { type: Object, default: null },
    purpose: { type: String, default: 'bill' },
});

const toast = useToast();
const showError = useApiError();

const ACTION_VERB = { personal: 'Send Money', agent: 'Cash Out', merchant: 'Payment' };

const form = reactive({
    purpose: props.purpose === 'wallet' || props.summary.due <= 0 ? 'wallet' : 'bill',
    amount: '',
    gateway: props.gateways[0]?.gateway ?? '',
    trx_id: '',
    sender_number: '',
});
const busy = ref(false);

const selected = computed(() => props.gateways.find((g) => g.gateway === form.gateway) ?? null);
const amountNumber = computed(() => Number(form.amount) || 0);
const amountError = computed(() => {
    if (!selected.value || !form.amount) return '';
    if (amountNumber.value < selected.value.min_amount) return `Minimum ${fmtMoney(selected.value.min_amount)}`;
    if (amountNumber.value > selected.value.max_amount) return `Maximum ${fmtMoney(selected.value.max_amount)}`;
    return '';
});
const canPay = computed(() => selected.value && amountNumber.value > 0 && !amountError.value && !busy.value);

watch(
    () => form.purpose,
    (p) => (form.amount = p === 'bill' && props.summary.due > 0 ? roundMoney(props.summary.due) : ''),
    { immediate: true }
);

async function payOnline() {
    busy.value = true;
    try {
        const res = await axios.post('/customer-portal/pay/start', { gateway: form.gateway, amount: form.amount, purpose: form.purpose });
        window.location.href = res.data.redirect;
    } catch (err) {
        showError(err);
        busy.value = false;
    }
}

async function submitManual() {
    busy.value = true;
    try {
        const res = await axios.post('/customer-portal/pay/manual', {
            gateway: form.gateway, amount: form.amount, purpose: form.purpose, trx_id: form.trx_id, sender_number: form.sender_number,
        });
        toast.success(res.data.message);
        router.visit(`/customer-portal/pay?ref=${res.data.ref}`, { preserveScroll: false });
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}

function copy(text) {
    navigator.clipboard?.writeText(text).then(() => toast.success('Copied'));
}

const RESULT = {
    completed: { icon: 'bi-check-circle-fill', cls: 'border-emerald-200 bg-emerald-50 text-emerald-800', title: 'res_completed' },
    pending_review: { icon: 'bi-hourglass-split', cls: 'border-amber-200 bg-amber-50 text-amber-800', title: 'res_pending_review' },
    initiated: { icon: 'bi-arrow-repeat', cls: 'border-slate-200 bg-slate-50 text-slate-700', title: 'res_initiated' },
    failed: { icon: 'bi-x-circle-fill', cls: 'border-red-200 bg-red-50 text-red-800', title: 'res_failed' },
    cancelled: { icon: 'bi-x-circle', cls: 'border-slate-200 bg-slate-50 text-slate-700', title: 'res_cancelled' },
    rejected: { icon: 'bi-x-octagon-fill', cls: 'border-red-200 bg-red-50 text-red-800', title: 'res_rejected' },
};
</script>

<template>
    <PortalLayout :user-name="customer.name" logout-url="/customer-portal/logout">
        <h1 class="mb-4 text-lg font-semibold text-slate-800">{{ t('pay_wallet') }}</h1>

        <!-- Result after returning from a gateway / submitting a TrxID -->
        <div v-if="result" class="mb-4 flex items-start gap-3 rounded-lg border p-4" :class="RESULT[result.status]?.cls">
            <i class="bi text-2xl" :class="RESULT[result.status]?.icon"></i>
            <div class="text-sm">
                <div class="font-semibold">{{ RESULT[result.status] ? t(RESULT[result.status].title) : '' }}</div>
                <div>
                    {{ t('via', { amount: fmtMoney(result.amount), method: GATEWAY_STYLES[result.gateway]?.label }) }}
                    <template v-if="result.trx_id"> · TrxID <span class="font-mono">{{ result.trx_id }}</span></template>
                </div>
                <div v-if="result.status === 'completed'">{{ t('account_updated') }}</div>
                <div v-else-if="result.status === 'pending_review'">{{ t('will_add_after_check') }}</div>
                <div v-else-if="result.failure_reason" class="opacity-80">{{ result.failure_reason }}</div>
            </div>
        </div>

        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('amount_due') }}</div>
                <div class="mt-1 text-2xl font-bold" :class="summary.due > 0 ? 'text-red-600' : 'text-slate-800'">{{ fmtMoney(summary.due) }}</div>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-4 shadow-sm">
                <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-emerald-700"><i class="bi bi-wallet2"></i> {{ t('wallet_balance') }}</div>
                <div class="mt-1 text-2xl font-bold text-emerald-700">{{ fmtMoney(summary.wallet) }}</div>
                <div class="text-xs text-slate-500">{{ t('used_for_next_bills') }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('res_pending_review') }}</div>
                <div class="mt-1 text-2xl font-bold text-amber-600">{{ fmtMoney(summary.pending) }}</div>
            </div>
        </div>

        <div v-if="!gateways.length" class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500 shadow-sm">
            <i class="bi bi-credit-card mb-2 block text-3xl text-slate-300"></i>
            {{ t('no_online_payment') }}
        </div>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:col-span-3">
                <!-- 1. what -->
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('step_what') }}</div>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="rounded-lg border p-3 text-start transition"
                            :class="form.purpose === 'bill' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'"
                            :disabled="summary.due <= 0"
                            @click="form.purpose = 'bill'"
                        >
                            <i class="bi bi-receipt text-lg text-brand-600"></i>
                            <div class="mt-1 text-sm font-semibold text-slate-800">{{ t('pay_bill') }}</div>
                            <div class="text-xs text-slate-500">{{ summary.due > 0 ? t('x_due', { amount: fmtMoney(summary.due) }) : t('nothing_due') }}</div>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border p-3 text-start transition"
                            :class="form.purpose === 'wallet' ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'"
                            @click="form.purpose = 'wallet'"
                        >
                            <i class="bi bi-wallet2 text-lg text-emerald-600"></i>
                            <div class="mt-1 text-sm font-semibold text-slate-800">{{ t('add_to_wallet') }}</div>
                            <div class="text-xs text-slate-500">{{ t('pay_in_advance') }}</div>
                        </button>
                    </div>
                    <p v-if="form.purpose === 'wallet' && summary.due > 0" class="mt-2 text-xs text-amber-700">
                        <i class="bi bi-info-circle"></i> {{ t('wallet_due_note', { amount: fmtMoney(summary.due) }) }}
                    </p>
                </div>

                <!-- 2. how much -->
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('step_amount') }}</div>
                    <div class="flex items-center rounded-lg border px-3" :class="amountError ? 'border-red-400' : 'border-slate-300'">
                        <span class="text-lg font-semibold text-slate-400">{{ cur() }}</span>
                        <input v-model="form.amount" type="number" min="1" :step="moneyStep()" placeholder="0.00" class="w-full border-none bg-transparent px-2 py-2.5 text-lg font-semibold outline-none" />
                    </div>
                    <p v-if="amountError" class="mt-1 text-xs text-red-600">{{ amountError }}</p>
                    <div v-if="form.purpose === 'wallet'" class="mt-2 flex flex-wrap gap-2">
                        <button v-for="v in [500, 1000, 2000, 5000]" :key="v" type="button" class="rounded-full border border-slate-200 px-3 py-1 text-xs hover:border-emerald-400 hover:bg-emerald-50" @click="form.amount = v">+ {{ fmtMoney(v) }}</button>
                    </div>
                </div>

                <!-- 3. method -->
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('step_method') }}</div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            v-for="g in gateways"
                            :key="g.gateway"
                            type="button"
                            class="flex flex-col items-center gap-1.5 rounded-lg border p-3 transition"
                            :class="form.gateway === g.gateway ? `ring-2 ${GATEWAY_STYLES[g.gateway].ring} border-transparent ${GATEWAY_STYLES[g.gateway].soft}` : 'border-slate-200 hover:border-slate-300'"
                            @click="form.gateway = g.gateway"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl text-lg font-bold text-white" :class="GATEWAY_STYLES[g.gateway].badge">{{ GATEWAY_STYLES[g.gateway].initial }}</span>
                            <span class="text-sm font-medium text-slate-800">{{ g.label }}</span>
                            <span v-if="g.gateway === 'sslcommerz'" class="text-[10px] text-slate-400">{{ t('card_bank_wallet') }}</span>
                            <span v-else-if="g.gateway === 'stripe'" class="text-[10px] text-slate-400">{{ t('card') }}</span>
                            <span v-else-if="g.gateway === 'paypal'" class="text-[10px] text-slate-400">{{ t('paypal_card') }}</span>
                        </button>
                    </div>
                </div>

                <!-- 4. pay -->
                <div v-if="selected" class="rounded-lg bg-slate-50 p-4">
                    <p v-if="selected.instructions" class="mb-3 text-xs text-slate-600"><i class="bi bi-info-circle"></i> {{ selected.instructions }}</p>

                    <button
                        v-if="selected.mode === 'api'"
                        type="button"
                        :disabled="!canPay"
                        class="flex w-full items-center justify-center gap-2 rounded-lg py-3 text-sm font-semibold text-white shadow transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50"
                        :class="GATEWAY_STYLES[selected.gateway].badge"
                        @click="payOnline"
                    >
                        <i class="bi" :class="busy ? 'bi-arrow-repeat animate-spin' : 'bi-lock-fill'"></i>
                        {{ busy ? t('opening', { name: selected.label }) : t('pay_x_with', { amount: fmtMoney(amountNumber), name: selected.label }) }}
                    </button>

                    <form v-else class="space-y-3" @submit.prevent="submitManual">
                        <ol class="space-y-2 text-sm text-slate-700">
                            <li class="flex gap-2">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" :class="GATEWAY_STYLES[selected.gateway].badge">1</span>
                                <span>
                                    {{ t('manual_step1', { name: selected.label, action: ACTION_VERB[selected.manual_account_type] || 'Send Money' }) }}
                                    <button type="button" class="font-mono font-semibold underline decoration-dotted" :class="GATEWAY_STYLES[selected.gateway].text" @click="copy(selected.manual_number)">
                                        {{ selected.manual_number }} <i class="bi bi-copy text-xs"></i>
                                    </button>
                                </span>
                            </li>
                            <li class="flex gap-2">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" :class="GATEWAY_STYLES[selected.gateway].badge">2</span>
                                <span>{{ t('manual_step2', { amount: fmtMoney(amountNumber), code: customer.code }) }}</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" :class="GATEWAY_STYLES[selected.gateway].badge">3</span>
                                <span>{{ t('manual_step3') }}</span>
                            </li>
                        </ol>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ t('trx_id') }}</label>
                                <input v-model="form.trx_id" required placeholder="e.g. 9FT4K2L8QP" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm uppercase" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ t('your_number', { name: selected.label }) }}</label>
                                <input v-model="form.sender_number" required inputmode="numeric" placeholder="01XXXXXXXXX" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <button
                            type="submit"
                            :disabled="!canPay"
                            class="flex w-full items-center justify-center gap-2 rounded-lg py-3 text-sm font-semibold text-white shadow transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50"
                            :class="GATEWAY_STYLES[selected.gateway].badge"
                        >
                            <i class="bi" :class="busy ? 'bi-arrow-repeat animate-spin' : 'bi-send-check'"></i> {{ t('submit_payment') }}
                        </button>
                    </form>
                </div>
            </div>

            <!-- Open bills -->
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('open_bills') }}</div>
                <div v-for="i in invoices" :key="i.id" class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                    <div>
                        <div class="font-medium text-slate-700">{{ i.invoice_no }}</div>
                        <div class="text-xs text-slate-400">{{ t('due_on', { date: fmtDate(i.due_date) }) }}</div>
                    </div>
                    <div class="font-semibold text-red-600">{{ fmtMoney(i.due) }}</div>
                </div>
                <div v-if="!invoices.length" class="py-6 text-center text-sm text-slate-400"><i class="bi bi-check2-circle text-emerald-500"></i> {{ t('all_paid') }}</div>
                <p class="mt-3 text-xs text-slate-500">{{ t('oldest_first') }}</p>
            </div>
        </div>

        <!-- History -->
        <div v-if="history.length" class="mt-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700">{{ t('recent_online') }}</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">{{ t('date') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('method') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('for') }}</th>
                            <th class="px-3 py-2 font-medium">TrxID</th>
                            <th class="px-3 py-2 text-end font-medium">{{ t('amount') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in history" :key="h.id" class="border-b border-slate-100">
                            <td class="px-3 py-2">{{ fmtDate(h.created_at) }}</td>
                            <td class="px-3 py-2">{{ GATEWAY_STYLES[h.gateway]?.label }}</td>
                            <td class="px-3 py-2">{{ h.purpose === 'wallet' ? t('wallet') : t('bill') }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ h.trx_id || '—' }}</td>
                            <td class="px-3 py-2 text-end font-medium">{{ money(h.amount) }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge :status="h.status" />
                                <div v-if="['rejected', 'failed'].includes(h.status) && h.failure_reason" class="text-xs text-slate-400">{{ h.failure_reason }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>
