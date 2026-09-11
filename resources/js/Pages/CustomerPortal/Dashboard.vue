<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';

const props = defineProps({
    customer: { type: Object, required: true },
});
</script>

<template>
    <PortalLayout :user-name="customer.name" logout-url="/customer-portal/logout">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">Welcome, {{ customer.name }}</h1>
            <Link href="/customer-portal/profile" class="text-sm font-medium text-brand-600 hover:underline">
                <i class="bi bi-person-circle"></i> My Profile
            </Link>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Account Code</div>
                <div class="mt-1 text-lg font-bold text-slate-800">{{ customer.code }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Previous Due</div>
                <div class="mt-1 text-lg font-bold text-slate-800">{{ customer.previous_due }}</div>
            </div>
        </div>
    </PortalLayout>
</template>
