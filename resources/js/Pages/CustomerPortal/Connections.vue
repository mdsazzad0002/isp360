<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { money, fmtDate, label, expiryClass, fmtDateTime, fmtMoney } from '../../lib/isp';
import { printInvoice } from '../../lib/ispPrint';

const props = defineProps({
    customer: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
});
const page = usePage();

// Active = running now; everything else (pending, suspended, inactive, terminated) is inactive.
const active = computed(() => props.connections.filter((c) => c.status === 'active'));
const inactive = computed(() => props.connections.filter((c) => c.status !== 'active'));
const tab = ref(active.value.length || !inactive.value.length ? 'active' : 'inactive');
const rows = computed(() => (tab.value === 'active' ? active.value : inactive.value));
const expanded = ref(null);

const invoicesOf = (id) => props.invoices.filter((i) => i.connection_id === id);
const otherInvoices = computed(() => props.invoices.filter((i) => !i.connection_id));

async function printInv(inv) {
    const res = await axios.post('/customer-portal/invoice', { id: inv.id });
    printInvoice(res.data.invoice, page.props.company, res.data.customer_balance);
}

function toggle(c) {
    expanded.value = expanded.value === c.id ? null : c.id;
}
</script>

<template>
    <PortalLayout :user-name="customer.name" logout-url="/customer-portal/logout">
        <div class="space-y-3">
            <!-- Profile header: same layout as the admin customer page -->
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <img :src="customer.image ? '/' + customer.image : '/noImage.jpg'" class="h-14 w-14 rounded-md border border-slate-200 object-cover" />
                        <div>
                            <h1 class="text-lg font-semibold text-slate-800">
                                {{ customer.name }}
                                <span
                                    v-if="customer.account_status"
                                    class="ml-1 rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                    :class="customer.account_status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-100 text-slate-600'"
                                >{{ label(customer.account_status) }}</span>
                            </h1>
                            <div class="text-sm text-slate-500">{{ customer.code }} · {{ customer.phone }}<span v-if="customer.email"> · {{ customer.email }}</span></div>
                            <div class="text-sm text-slate-500">
                                {{ [customer.zone?.name, customer.area?.name, customer.box?.name].filter(Boolean).join(' → ') || 'No location set' }}
                                <span v-if="customer.address"> · {{ customer.address }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link href="/customer-portal/pay" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-700"><i class="bi bi-credit-card"></i> Pay bill</Link>
                        <Link href="/customer-portal/profile" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm"><i class="bi bi-pen"></i> Edit profile</Link>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Current due</div><div class="text-lg font-semibold" :class="summary.balance > 0 ? 'text-red-600' : 'text-slate-800'">{{ money(Math.max(0, summary.balance)) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Overdue</div><div class="text-lg font-semibold text-red-600">{{ money(summary.overdue) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Wallet balance</div><div class="text-lg font-semibold text-emerald-700">{{ money(summary.advance) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Total billed</div><div class="text-lg font-semibold text-slate-800">{{ money(summary.total_billed) }}</div></div>
                    <div class="rounded-md border border-slate-200 p-2"><div class="text-[11px] uppercase text-slate-400">Total paid</div><div class="text-lg font-semibold text-slate-800">{{ money(summary.total_paid) }}</div></div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-2">
                    <button
                        v-for="t in [['active', 'Active', active.length], ['inactive', 'Inactive', inactive.length], ['collection', 'Collection', payments.length]]"
                        :key="t[0]"
                        type="button"
                        class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm"
                        :class="tab === t[0] ? 'border-brand-500 font-medium text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                        @click="tab = t[0]; expanded = null"
                    >
                        <i v-if="t[0] === 'active'" class="bi bi-circle-fill mr-1 text-[8px] text-emerald-500"></i>
                        <i v-else-if="t[0] === 'inactive'" class="bi bi-circle-fill mr-1 text-[8px] text-slate-400"></i>
                        {{ t[1] }} <span class="text-xs text-slate-400">({{ t[2] }})</span>
                    </button>
                </div>

                <div class="overflow-x-auto p-3">
                    <!-- Active / inactive connections with their collection -->
                    <table v-if="tab !== 'collection'" class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="w-6 px-2 py-2"></th>
                                <th class="px-2 py-2 font-medium">Code</th>
                                <th class="px-2 py-2 font-medium">Package</th>
                                <th class="px-2 py-2 font-medium">Type / User</th>
                                <th class="px-2 py-2 font-medium">{{ tab === 'active' ? 'Expire date' : 'Since' }}</th>
                                <th class="px-2 py-2 text-right font-medium">Billed</th>
                                <th class="px-2 py-2 text-right font-medium">Collected</th>
                                <th class="px-2 py-2 text-right font-medium">Due</th>
                                <th class="px-2 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="c in rows" :key="c.id">
                                <tr class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="toggle(c)">
                                    <td class="px-2 py-2 text-slate-400"><i class="bi text-xs" :class="expanded === c.id ? 'bi-chevron-down' : 'bi-chevron-right'"></i></td>
                                    <td class="px-2 py-2 font-medium text-brand-600">{{ c.code }}</td>
                                    <td class="px-2 py-2">
                                        {{ c.package?.name }}
                                        <div class="text-xs text-slate-400">{{ c.package?.download_mbps }}/{{ c.package?.upload_mbps }} Mbps · {{ fmtMoney(Number(c.package?.price) - Number(c.discount)) }} / {{ label(c.package?.billing_cycle) }}</div>
                                    </td>
                                    <td class="px-2 py-2">{{ label(c.connection_type) }} <div class="text-xs text-slate-400">{{ c.pppoe_username || c.static_ip || '—' }}</div></td>
                                    <td class="px-2 py-2">
                                        <template v-if="tab === 'active'"><span :class="expiryClass(c.expire_at)">{{ fmtDateTime(c.expire_at) || 'Unpaid' }}</span></template>
                                        <template v-else>{{ fmtDate(c.terminated_at || c.suspended_at) || '—' }}</template>
                                    </td>
                                    <td class="px-2 py-2 text-right">{{ money(c.billed) }}</td>
                                    <td class="px-2 py-2 text-right text-emerald-700">{{ money(c.collected) }}</td>
                                    <td class="px-2 py-2 text-right font-medium" :class="c.due > 0 ? 'text-red-600' : ''">{{ money(c.due) }}</td>
                                    <td class="px-2 py-2">
                                        <StatusBadge :status="c.status" />
                                        <div v-if="c.status === 'suspended'" class="text-xs text-amber-700">{{ c.suspension_reason }}<template v-if="c.due > 0"> · pay the due to restore</template></div>
                                    </td>
                                </tr>
                                <!-- bill-by-bill collection of this connection -->
                                <tr v-if="expanded === c.id" class="bg-slate-50/60">
                                    <td></td>
                                    <td colspan="8" class="px-2 pb-3 pt-1">
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr class="text-left text-slate-500">
                                                    <th class="py-1.5 font-medium">Bill</th>
                                                    <th class="py-1.5 font-medium">Period</th>
                                                    <th class="py-1.5 font-medium">Due date</th>
                                                    <th class="py-1.5 text-right font-medium">Amount</th>
                                                    <th class="py-1.5 text-right font-medium">Collected</th>
                                                    <th class="py-1.5 text-right font-medium">Due</th>
                                                    <th class="py-1.5 font-medium">Status</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="i in invoicesOf(c.id)" :key="i.id" class="border-t border-slate-200/70">
                                                    <td class="py-1.5">{{ i.invoice_no }}</td>
                                                    <td class="py-1.5">{{ i.period_start ? `${fmtDateTime(i.period_start)} – ${fmtDateTime(i.period_end)}` : fmtDate(i.invoice_date) }}</td>
                                                    <td class="py-1.5">{{ fmtDate(i.due_date) }}</td>
                                                    <td class="py-1.5 text-right">{{ money(i.total) }}</td>
                                                    <td class="py-1.5 text-right text-emerald-700">{{ money(i.paid) }}</td>
                                                    <td class="py-1.5 text-right font-medium" :class="Number(i.due) > 0 ? 'text-red-600' : ''">{{ money(i.due) }}</td>
                                                    <td class="py-1.5"><StatusBadge :status="i.status" /></td>
                                                    <td class="py-1.5 text-right">
                                                        <button type="button" class="text-slate-500 hover:text-brand-600" title="Print / PDF" @click.stop="printInv(i)"><i class="bi bi-printer"></i></button>
                                                    </td>
                                                </tr>
                                                <tr v-if="!invoicesOf(c.id).length"><td colspan="8" class="py-3 text-center text-slate-400">No bills for this connection yet</td></tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!rows.length">
                                <td colspan="9" class="px-2 py-6 text-center text-slate-400">{{ tab === 'active' ? 'No active connections' : 'No inactive connections' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Collection: every payment received from this customer -->
                    <template v-else>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                    <th class="px-2 py-2 font-medium">Receipt</th>
                                    <th class="px-2 py-2 font-medium">Date</th>
                                    <th class="px-2 py-2 font-medium">Method</th>
                                    <th class="px-2 py-2 text-right font-medium">Amount</th>
                                    <th class="px-2 py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in payments" :key="p.id" class="border-b border-slate-100">
                                    <td class="px-2 py-2">{{ p.receipt_no }}</td>
                                    <td class="px-2 py-2">{{ fmtDate(p.payment_date) }}</td>
                                    <td class="px-2 py-2">{{ label(p.method) }} <span class="text-xs text-slate-400">{{ p.transaction_id }}</span></td>
                                    <td class="px-2 py-2 text-right font-medium">{{ money(p.amount) }}</td>
                                    <td class="px-2 py-2"><StatusBadge :status="p.status" /></td>
                                </tr>
                                <tr v-if="!payments.length"><td colspan="5" class="px-2 py-6 text-center text-slate-400">No payments yet</td></tr>
                            </tbody>
                        </table>
                        <p v-if="otherInvoices.length" class="mt-3 text-xs text-slate-500">
                            {{ otherInvoices.length }} bill(s) are not tied to a connection (e.g. opening balance or one-off charges). They appear on the Dashboard under Bills.
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
