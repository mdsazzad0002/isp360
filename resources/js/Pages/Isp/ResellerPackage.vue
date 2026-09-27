<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { money, label } from '../../lib/isp';

// Packages resellers sell (customized copies of company packages). Read-only for the
// company: when a company package changes, each reseller reviews and accepts it.
defineOptions({ layout: AppLayout });
const props = defineProps({ resellers: { type: Array, default: () => [] } });

const pkgStatus = ref('');
const resellerId = ref('');
const packages = ref([]);
function loadPackages() {
    axios.post('/isp/get-reseller-package-requests', { status: pkgStatus.value, resellerId: resellerId.value }).then((r) => (packages.value = r.data));
}
const FIELD_LABELS = { company_price: 'price', download_mbps: 'download', upload_mbps: 'upload', billing_cycle: 'cycle', validity_days: 'validity', installation_fee: 'installation fee', activation_fee: 'activation fee', network_profile: 'router profile' };

onMounted(loadPackages);
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Reseller Packages</h1>

                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <select v-model="resellerId" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="loadPackages">
                        <option value="">All resellers</option>
                        <option v-for="r in resellers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.code }})</option>
                    </select>
                    <select v-model="pkgStatus" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="loadPackages">
                        <option value="">All reseller packages</option>
                        <option value="waiting">Waiting for reseller review</option>
                    </select>
                    <span class="text-xs text-slate-500">When you change a company package, each reseller copy keeps its terms until that reseller reviews and accepts the change.</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                                <th class="px-3 py-2 font-medium">Reseller</th>
                                <th class="px-3 py-2 font-medium">Package</th>
                                <th class="px-3 py-2 font-medium">Company package</th>
                                <th class="px-3 py-2 text-end font-medium">Company price (accepted)</th>
                                <th class="px-3 py-2 text-end font-medium">Reseller price</th>
                                <th class="px-3 py-2 text-end font-medium">Reseller margin</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in packages" :key="row.id" class="border-b border-slate-100 align-top hover:bg-slate-50">
                                <td class="px-3 py-2">{{ row.reseller?.name }}<div class="text-xs text-slate-400">{{ row.reseller?.code }}</div></td>
                                <td class="px-3 py-2">
                                    {{ row.name }}
                                    <div class="text-xs text-slate-400">{{ row.download_mbps }}/{{ row.upload_mbps }} Mbps · {{ label(row.billing_cycle) }} · {{ row.active_connections }} live</div>
                                </td>
                                <td class="px-3 py-2">{{ row.base_package?.name || '—' }}<span v-if="row.base_package?.visibility === 'hidden'" class="ms-1 text-xs text-slate-400">(hidden)</span></td>
                                <td class="px-3 py-2 text-end">
                                    {{ row.base_price !== null ? money(row.base_price) : '—' }}
                                    <div v-if="row.base_changes?.company_price" class="text-xs text-amber-700">now {{ money(row.base_changes.company_price[1]) }}</div>
                                </td>
                                <td class="px-3 py-2 text-end font-medium">{{ money(row.price) }}</td>
                                <td class="px-3 py-2 text-end text-emerald-700">{{ row.base_price !== null ? money(row.price - row.base_price) : '—' }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="row.base_changes" class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs text-amber-700" :title="Object.keys(row.base_changes).map((f) => FIELD_LABELS[f] || f).join(', ')">
                                        Waiting for reseller ({{ Object.keys(row.base_changes).map((f) => FIELD_LABELS[f] || f).join(', ') }})
                                    </span>
                                    <span v-else-if="row.is_active" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Up to date</span>
                                    <span v-else class="rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Inactive</span>
                                </td>
                            </tr>
                            <tr v-if="!packages.length"><td colspan="7" class="px-3 py-6 text-center text-slate-400">Nothing here</td></tr>
                        </tbody>
                    </table>
                </div>
                    </div>
    </div>
</template>
