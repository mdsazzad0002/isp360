<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    customerCount: { type: Number, default: 0 },
    totalDue: { type: [Number, String], default: 0 },
});
</script>

<template>
    <PortalLayout user-name="Reseller" logout-url="/reseller/logout">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">My Dashboard</h1>
            <Link href="/reseller/profile" class="text-sm font-medium text-brand-600 hover:underline">
                <i class="bi bi-person-circle"></i> My Profile
            </Link>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">My Customers</div>
                <div class="mt-1 text-2xl font-bold text-slate-800">{{ customerCount }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Due</div>
                <div class="mt-1 text-2xl font-bold text-slate-800">{{ totalDue }}</div>
            </div>
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
                            <th class="px-2 py-2 font-medium">Prev. Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in customers" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.code }}</td>
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="px-2 py-1.5">{{ row.address }}</td>
                            <td class="px-2 py-1.5">{{ row.previous_due }}</td>
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
