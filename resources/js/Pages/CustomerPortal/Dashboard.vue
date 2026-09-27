<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import LedgerStatement from '../../Components/Isp/LedgerStatement.vue';
import { money, fmtDate, label, fmtDateTime, fmtMoney } from '../../lib/isp';
import { printInvoice } from '../../lib/ispPrint';

const { t: i18nT } = useI18n();
const t = (key, values) => i18nT(`portal.${key}`, values ?? {});
const props = defineProps({
    customer: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    pendingLegal: { type: Array, default: () => [] },
});
// terms / privacy to accept (recorded with time, IP and browser)
const legalOpen = ref(true);
async function acceptLegal() {
    await axios.post('/customer-portal/accept-legal');
    legalOpen.value = false;
}
const page = usePage();
const tab = ref('invoices');

async function printInv(inv) {
    const res = await axios.post('/customer-portal/invoice', { id: inv.id });
    printInvoice(res.data.invoice, page.props.company, res.data.customer_balance);
}
</script>

<template>
    <PortalLayout :user-name="customer.name" logout-url="/customer-portal/logout">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">{{ t('welcome', { name: customer.name }) }}</h1>
                <p class="text-sm text-slate-500">{{ t('account_no', { code: customer.code }) }}<span v-if="customer.area"> · {{ customer.area.name }}</span></p>
            </div>
        </div>

        <div v-if="pendingLegal.length && legalOpen" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <h2 class="mb-2 text-sm font-semibold text-amber-900">{{ t('legal_review') }}</h2>
            <details v-for="d in pendingLegal" :key="d.id" class="mb-2 rounded border border-amber-200 bg-white p-2 text-sm">
                <summary class="cursor-pointer font-medium text-slate-700">{{ t('legal_version', { title: d.title, version: d.version }) }}</summary>
                <div class="mt-2 max-h-64 overflow-y-auto whitespace-pre-line text-slate-600">{{ d.body }}</div>
            </details>
            <button type="button" class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700" @click="acceptLegal">{{ t('i_accept') }}</button>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('amount_due') }}</div>
                <div class="mt-1 text-2xl font-bold" :class="summary.balance > 0 ? 'text-red-600' : 'text-slate-800'">{{ fmtMoney(Math.max(0, summary.balance)) }}</div>
                <div v-if="summary.next_due_date && summary.balance > 0" class="text-xs text-slate-500">{{ t('pay_by', { date: fmtDate(summary.next_due_date) }) }}</div>
                <Link v-if="summary.balance > 0" href="/customer-portal/pay" class="mt-2 inline-flex items-center gap-1 rounded-md bg-brand-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-600">
                    <i class="bi bi-credit-card"></i> {{ t('pay_now') }}
                </Link>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('overdue') }}</div>
                <div class="mt-1 text-2xl font-bold" :class="summary.overdue > 0 ? 'text-red-600' : 'text-slate-800'">{{ fmtMoney(summary.overdue) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400"><i class="bi bi-wallet2"></i> {{ t('wallet_balance') }}</div>
                <div class="mt-1 text-2xl font-bold text-emerald-700">{{ fmtMoney(summary.advance) }}</div>
                <Link href="/customer-portal/pay?purpose=wallet" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:underline"><i class="bi bi-plus-circle"></i> {{ t('add_money') }}</Link>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('connections') }}</div>
                <div class="mt-1 text-2xl font-bold text-slate-800">{{ connections.length }}</div>
            </div>
        </div>

        <div v-for="c in connections" :key="c.id" class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <div class="font-semibold text-slate-800">{{ c.package?.name }} <span class="text-sm font-normal text-slate-500">{{ c.package?.download_mbps }}/{{ c.package?.upload_mbps }} Mbps</span></div>
                <div class="text-sm text-slate-500">{{ c.code }} · {{ label(c.connection_type) }}<span v-if="c.pppoe_username"> · {{ c.pppoe_username }}</span> · {{ fmtMoney(Number(c.package?.price) - Number(c.discount)) }} / {{ label(c.package?.billing_cycle) }}</div>
            </div>
            <div class="text-end">
                <StatusBadge :status="c.status" />
                <div v-if="c.status === 'suspended'" class="mt-1 text-xs text-amber-700">{{ t('suspended_note', { reason: c.suspension_reason }) }}</div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex gap-1 border-b border-slate-200 px-2">
                <button v-for="tb in [['invoices', t('bills')], ['payments', t('payments')], ['statement', t('statement')]]" :key="tb[0]" type="button" class="border-b-2 px-3 py-2.5 text-sm" :class="tab === tb[0] ? 'border-brand-500 font-medium text-brand-600' : 'border-transparent text-slate-500'" @click="tab = tb[0]">{{ tb[1] }}</button>
            </div>
            <div class="overflow-x-auto p-3">
                <table v-if="tab === 'invoices'" class="w-full text-sm">
                    <thead><tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600"><th class="px-2 py-2 font-medium">{{ t('bill') }}</th><th class="px-2 py-2 font-medium">{{ t('period') }}</th><th class="px-2 py-2 font-medium">{{ t('due_date') }}</th><th class="px-2 py-2 text-end font-medium">{{ t('amount') }}</th><th class="px-2 py-2 text-end font-medium">{{ t('due') }}</th><th class="px-2 py-2 font-medium">{{ t('status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="i in invoices" :key="i.id" class="border-b border-slate-100">
                            <td class="px-2 py-2">{{ i.invoice_no }}</td>
                            <td class="px-2 py-2 text-xs">{{ i.period_start ? `${fmtDateTime(i.period_start)} – ${fmtDateTime(i.period_end)}` : fmtDate(i.invoice_date) }}</td>
                            <td class="px-2 py-2">{{ fmtDate(i.due_date) }}</td>
                            <td class="px-2 py-2 text-end">{{ money(i.total) }}</td>
                            <td class="px-2 py-2 text-end font-medium" :class="Number(i.due) > 0 ? 'text-red-600' : ''">{{ money(i.due) }}</td>
                            <td class="px-2 py-2"><StatusBadge :status="i.status" /></td>
                            <td class="px-2 py-2 text-end"><button type="button" class="text-slate-500 hover:text-brand-600" :title="t('print')" @click="printInv(i)"><i class="bi bi-printer"></i></button></td>
                        </tr>
                        <tr v-if="!invoices.length"><td colspan="7" class="px-2 py-6 text-center text-slate-400">{{ t('no_bills') }}</td></tr>
                    </tbody>
                </table>
                <table v-if="tab === 'payments'" class="w-full text-sm">
                    <thead><tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600"><th class="px-2 py-2 font-medium">{{ t('receipt') }}</th><th class="px-2 py-2 font-medium">{{ t('date') }}</th><th class="px-2 py-2 font-medium">{{ t('method') }}</th><th class="px-2 py-2 text-end font-medium">{{ t('amount') }}</th><th class="px-2 py-2 font-medium">{{ t('status') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="p in payments" :key="p.id" class="border-b border-slate-100">
                            <td class="px-2 py-2">{{ p.receipt_no }}</td>
                            <td class="px-2 py-2">{{ fmtDate(p.payment_date) }}</td>
                            <td class="px-2 py-2">{{ label(p.method) }} <span class="text-xs text-slate-400">{{ p.transaction_id }}</span></td>
                            <td class="px-2 py-2 text-end font-medium">{{ money(p.amount) }}</td>
                            <td class="px-2 py-2"><StatusBadge :status="p.status" /></td>
                        </tr>
                        <tr v-if="!payments.length"><td colspan="5" class="px-2 py-6 text-center text-slate-400">{{ t('no_payments') }}</td></tr>
                    </tbody>
                </table>
                <LedgerStatement v-if="tab === 'statement'" :customer="customer" endpoint="/customer-portal/statement" />
            </div>
        </div>
    </PortalLayout>
</template>
