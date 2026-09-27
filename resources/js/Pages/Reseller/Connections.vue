<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import { money, fmtDate, label, expiryClass, fmtDateTime, fmtMoney } from '../../lib/isp';

// Connections of the reseller's customers (read-only; the company creates and runs them).
const props = defineProps({
    reseller: { type: Object, required: true },
    connections: { type: Array, default: () => [] },
});

const status = ref('');
const search = ref('');

const counts = computed(() => {
    const c = { '': props.connections.length };
    props.connections.forEach((r) => (c[r.status] = (c[r.status] || 0) + 1));
    return c;
});
const tabs = computed(() => [['', 'All'], ['active', 'Active'], ['suspended', 'Suspended'], ['pending', 'Pending'], ['inactive', 'Inactive'], ['terminated', 'Terminated']].filter(([k]) => k === '' || counts.value[k]));

const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return props.connections.filter((r) => {
        if (status.value && r.status !== status.value) return false;
        if (!t) return true;
        return `${r.code} ${r.pppoe_username || ''} ${r.customer?.name || ''} ${r.customer?.phone || ''} ${r.customer?.code || ''} ${r.package?.name || ''}`.toLowerCase().includes(t);
    });
});
</script>

<template>
    <PortalLayout :user-name="reseller.name" logout-url="/reseller/logout">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">My Connections</h1>
            <Link href="/reseller/payments" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-700"><i class="bi bi-cash-coin"></i> Collect payment</Link>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-3 pt-2">
                <div class="flex gap-1 overflow-x-auto">
                    <button
                        v-for="[k, text] in tabs"
                        :key="k"
                        type="button"
                        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm"
                        :class="status === k ? 'border-brand-500 font-medium text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                        @click="status = k"
                    >{{ text }} <span class="text-xs text-slate-400">({{ counts[k] || 0 }})</span></button>
                </div>
                <input v-model="search" placeholder="Search customer, code, username..." class="mb-2 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-64" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Connection</th>
                            <th class="px-3 py-2 font-medium">Customer</th>
                            <th class="px-3 py-2 font-medium">Package</th>
                            <th class="px-3 py-2 font-medium">Type / Username</th>
                            <th class="px-3 py-2 font-medium">Activated</th>
                            <th class="px-3 py-2 font-medium">Expire date</th>
                            <th class="px-3 py-2 text-end font-medium">Customer due</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filtered" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium">{{ row.code }}</td>
                            <td class="px-3 py-2">
                                {{ row.customer?.name }}
                                <div class="text-xs text-slate-400">{{ row.customer?.code }} · {{ row.customer?.phone }}</div>
                            </td>
                            <td class="px-3 py-2">
                                {{ row.package?.name }}
                                <div class="text-xs text-slate-400">{{ row.package?.download_mbps }} Mbps · {{ fmtMoney(row.package?.price - row.discount) }} / {{ label(row.package?.billing_cycle) }}</div>
                            </td>
                            <td class="px-3 py-2">{{ label(row.connection_type) }}<div class="text-xs text-slate-400">{{ row.pppoe_username }}</div></td>
                            <td class="px-3 py-2">{{ fmtDate(row.activation_date) || '—' }}</td>
                            <td class="px-3 py-2" :class="expiryClass(row.expire_at)">{{ fmtDateTime(row.expire_at) || 'Unpaid' }}</td>
                            <td class="px-3 py-2 text-end" :class="row.customer?.ledger_balance > 0 ? 'font-medium text-red-600' : 'text-slate-500'">{{ money(Math.max(0, row.customer?.ledger_balance || 0)) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="row.status" /></td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No connections</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>
