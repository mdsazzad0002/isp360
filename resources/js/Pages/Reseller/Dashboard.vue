<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import { money } from '../../lib/isp';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    customerCount: { type: Number, default: 0 },
    totalDue: { type: [Number, String], default: 0 },
    connectionCount: { type: Number, default: 0 },
    wallet: { type: Object, default: () => ({}) },
});
</script>

<template>
    <PortalLayout user-name="Reseller" logout-url="/reseller/logout">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">My Dashboard</h1>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">My Customers</div>
                <div class="mt-1 text-2xl font-bold text-slate-800">{{ customerCount }}</div>
            </div>
            <Link href="/reseller/connections" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Active Connections</div>
                <div class="mt-1 text-2xl font-bold text-slate-800">{{ connectionCount }}</div>
            </Link>
            <Link href="/reseller/payments" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Customers' Due</div>
                <div class="mt-1 text-2xl font-bold text-red-600">{{ money(totalDue) }}</div>
            </Link>
            <Link href="/reseller/withdrawals" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Earned</div>
                <div class="mt-1 text-2xl font-bold text-slate-800">{{ money(wallet.earned) }}</div>
            </Link>
            <Link href="/reseller/withdrawals" class="col-span-2 rounded-lg border p-4 shadow-sm lg:col-span-1" :class="wallet.balance < 0 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ wallet.balance < 0 ? 'I owe the company' : 'Wallet balance' }}</div>
                <div class="mt-1 text-2xl font-bold" :class="wallet.balance < 0 ? 'text-red-600' : 'text-emerald-700'">{{ money(Math.abs(wallet.balance || 0)) }}</div>
            </Link>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">My Customers</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Code</th>
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Phone</th>
                            <th class="px-2 py-2 font-medium">Address</th>
                            <th class="px-2 py-2 text-right font-medium">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in customers" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.code }}</td>
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="px-2 py-1.5">{{ row.address }}</td>
                            <td class="px-2 py-1.5 text-right" :class="row.ledger_balance > 0 ? 'text-red-600' : 'text-slate-500'">{{ money(Math.max(0, row.ledger_balance || 0)) }}</td>
                        </tr>
                        <tr v-if="!customers.length">
                            <td colspan="5" class="px-2 py-6 text-center text-slate-400">No customers assigned yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>
