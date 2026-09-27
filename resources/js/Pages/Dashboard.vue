<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { usePage, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '../Layouts/AppLayout.vue';
import GroupedBarChart from '../Components/Isp/GroupedBarChart.vue';
import BarList from '../Components/Isp/BarList.vue';
import { money, label } from '../lib/isp';

defineOptions({ layout: AppLayout });

const page = usePage();
const { t } = useI18n();
const stats = ref(null);
const denied = ref(false);

const conn = computed(() => stats.value?.connections || {});
const totalConnections = computed(() => Object.values(conn.value).reduce((s, v) => s + Number(v), 0));

onMounted(() => {
    axios
        .post('/isp/get-dashboard')
        .then((res) => (stats.value = res.data))
        .catch(() => (denied.value = true));
});
</script>

<template>
    <div class="mx-auto space-y-4 p-4 sm:p-6">
        <section class="rounded-xl bg-gradient-to-r from-brand-700 to-brand-600 px-6 py-5 text-white shadow-sm">
            <h1 class="text-xl font-bold">{{ t('dashboard.welcome_back', { name: page.props.auth?.user?.name }) }}</h1>
            <p class="mt-1 text-sm text-white/80">{{ t('dashboard.subtitle') }}</p>
        </section>

        <template v-if="stats">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                <Link href="/isp/payments" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm hover:border-brand-500">
                    <div class="text-xs text-slate-500">Today's collection</div>
                    <div class="text-xl font-semibold text-slate-800">{{ money(stats.today_collection) }}</div>
                </Link>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">This month collected</div>
                    <div class="text-xl font-semibold text-slate-800">{{ money(stats.month_collection) }}</div>
                    <div class="text-[11px] text-slate-400">billed {{ money(stats.month_billed) }}</div>
                </div>
                <Link href="/isp/due-report" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm hover:border-brand-500">
                    <div class="text-xs text-slate-500">Total outstanding due</div>
                    <div class="text-xl font-semibold text-slate-800">{{ money(stats.outstanding) }}</div>
                    <div class="text-[11px] text-slate-400">advance held {{ money(stats.advance) }}</div>
                </Link>
                <Link href="/isp/due-report" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm hover:border-brand-500">
                    <div class="flex items-center gap-1 text-xs text-slate-500"><i class="bi bi-exclamation-triangle-fill text-red-500"></i> Overdue</div>
                    <div class="text-xl font-semibold text-slate-800">{{ money(stats.overdue) }}</div>
                </Link>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Customers</div>
                    <div class="text-xl font-semibold text-slate-800">{{ stats.customers.total || 0 }}</div>
                    <div class="text-[11px] text-slate-400">{{ stats.customers.active || 0 }} active · {{ stats.customers.inactive || 0 }} inactive · {{ stats.customers.new_this_month || 0 }} new</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Box ports</div>
                    <div class="text-xl font-semibold text-slate-800">{{ stats.boxes.used }}<span class="text-sm font-normal text-slate-400"> / {{ stats.boxes.capacity }}</span></div>
                    <div class="text-[11px] text-slate-400">{{ stats.boxes.count }} boxes · {{ Math.max(0, stats.boxes.capacity - stats.boxes.used) }} free</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                    <h2 class="mb-2 text-sm font-semibold text-slate-700">Billed vs collected — last 12 months</h2>
                    <GroupedBarChart :rows="stats.months.map((m) => ({ label: m.month, billed: m.billed, collected: m.collected }))" :series="[{ key: 'billed', label: 'Billed', slot: 1 }, { key: 'collected', label: 'Collected', slot: 2 }]" />
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Connections <span class="font-normal text-slate-400">({{ totalConnections }})</span></h2>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <Link v-for="s in ['active', 'suspended', 'pending', 'inactive', 'terminated']" :key="s" href="/isp/connections" class="flex items-center justify-between rounded-md border border-slate-200 px-2.5 py-1.5 hover:border-brand-500">
                            <span class="text-slate-600">{{ label(s) }}</span><span class="font-semibold text-slate-800">{{ conn[s] || 0 }}</span>
                        </Link>
                        <div class="flex items-center justify-between rounded-md border border-slate-200 px-2.5 py-1.5"><span class="text-slate-600">New this month</span><span class="font-semibold text-slate-800">{{ stats.new_connections }}</span></div>
                    </div>
                    <h2 class="mb-2 mt-4 text-sm font-semibold text-slate-700">Collection by method — this month</h2>
                    <BarList :rows="stats.method_wise.map((m) => ({ label: label(m.label), value: Number(m.value) }))" is-money empty="No collections this month" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Live connections by package <span class="font-normal text-slate-400">({{ stats.active_packages }} active packages)</span></h2>
                    <BarList :rows="stats.package_wise.map((p) => ({ label: p.label, value: Number(p.value) }))" empty="No live connections yet" />
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Customers by area</h2>
                    <BarList :rows="stats.area_wise.map((a) => ({ label: `${a.label} · due ${money(a.due)}`, value: Number(a.value) }))" empty="No customers yet" />
                </div>
            </div>
        </template>
        <div v-else-if="!denied" class="p-8 text-center text-sm text-slate-400">Loading dashboard...</div>
    </div>
</template>
