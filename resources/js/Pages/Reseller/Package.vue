<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { money, label, useApiError, fmtMoney, cur, moneyStep } from '../../lib/isp';

// A reseller sells company packages under their own name and price. Speed, router
// profile and billing cycle come from the company package. When the company changes that
// package, the reseller's copy keeps its terms until the reseller reviews and saves it.
const props = defineProps({
    reseller: { type: Object, required: true },
    basePackages: { type: Array, default: () => [] },
});

const toast = useToast();
const showError = useApiError();

function blank() {
    return { id: null, base_package_id: '', name: '', code: '', price: '', description: '', is_active: true, price_change_reason: '' };
}
const form = reactive(blank());
const rows = ref([]);
const search = ref('');
const saving = ref(false);
const originalPrice = ref(null);
const reviewing = ref(null); // base_changes of the package being reviewed
const waitingCount = computed(() => rows.value.filter((r) => r.base_changes).length);

const FIELD_LABELS = { company_price: 'Company price', download_mbps: 'Download (Mbps)', upload_mbps: 'Upload (Mbps)', billing_cycle: 'Billing cycle', validity_days: 'Validity (days)', installation_fee: 'Installation fee', activation_fee: 'Activation fee', network_profile: 'Router profile' };
const MONEY_FIELDS = ['company_price', 'installation_fee', 'activation_fee'];
function fmtField(field, value) {
    if (MONEY_FIELDS.includes(field)) return money(value);
    if (field === 'billing_cycle') return label(value);
    return value === null || value === '' ? '—' : value;
}

const base = computed(() => props.basePackages.find((p) => p.id === Number(form.base_package_id)) || rows.value.find((r) => r.base_package_id === Number(form.base_package_id))?.base_package || null);
const margin = computed(() => (base.value && form.price !== '' ? Number(form.price) - Number(base.value.price) : null));
const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return t ? rows.value.filter((r) => `${r.name} ${r.code || ''}`.toLowerCase().includes(t)) : rows.value;
});
const priceChanged = computed(() => form.id && originalPrice.value !== null && Number(form.price) !== Number(originalPrice.value));

function load() {
    axios.post('/reseller/get-packages').then((r) => (rows.value = r.data));
}

function pickBase() {
    if (!base.value || form.id) return;
    if (!form.name) form.name = base.value.name;
    if (form.price === '' || Number(form.price) < Number(base.value.price)) form.price = Number(base.value.price);
}

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/reseller/package', { ...form });
        toast.success(res.data.message);
        reset();
        load();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

function reset() {
    Object.assign(form, blank());
    originalPrice.value = null;
    reviewing.value = null;
}

function edit(row) {
    Object.assign(form, blank(), {
        id: row.id,
        base_package_id: row.base_package_id ?? '',
        name: row.name,
        code: row.code ?? '',
        price: Number(row.price),
        description: row.description ?? '',
        is_active: row.is_active,
    });
    originalPrice.value = Number(row.price);
    reviewing.value = row.base_changes || null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function remove(row) {
    if (!(await confirmDialog({ title: `Delete package ${row.name}?` }))) return;
    try {
        const res = await axios.post('/reseller/delete-package', { id: row.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}

function statusOf(row) {
    if (row.base_changes) return { text: 'Company changed · review', cls: 'bg-amber-50 text-amber-700 border-amber-200' };
    return row.is_active ? { text: 'Live', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' } : { text: 'Inactive', cls: 'bg-slate-100 text-slate-600 border-slate-300' };
}

onMounted(load);
</script>

<template>
    <PortalLayout :user-name="reseller.name" logout-url="/reseller/logout">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">My Packages</h1>
        </div>

        <div v-if="waitingCount && !reviewing" class="mb-3 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
            <i class="bi bi-exclamation-triangle"></i>
            The company changed {{ waitingCount }} of your base packages. Review them below: your packages keep their current terms until you save them.
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">{{ reviewing ? `Review company change: ${form.name}` : form.id ? `Edit package: ${form.name}` : 'Customize a company package' }}</h2>
            <p class="mb-3 text-xs text-slate-500">Pick a company package, give it your name and price. Your earning is your price minus the company price.</p>
            <div v-if="reviewing" class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <div class="mb-1 font-semibold">The company changed this package:</div>
                <table class="w-full max-w-md">
                    <tr v-for="(pair, field) in reviewing" :key="field">
                        <td class="py-0.5 pe-3 text-amber-700">{{ FIELD_LABELS[field] || field }}</td>
                        <td class="py-0.5 pe-2 line-through opacity-70">{{ fmtField(field, pair[0]) }}</td>
                        <td class="py-0.5 font-semibold">→ {{ fmtField(field, pair[1]) }}</td>
                    </tr>
                </table>
                <div class="mt-1">Adjust your price if needed and save to accept. Until then your customers stay on the old terms.</div>
            </div>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-4" @submit.prevent="save">
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Company package</label>
                    <select v-model="form.base_package_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" @change="pickBase">
                        <option value="" disabled>— Select —</option>
                        <option v-for="p in basePackages" :key="p.id" :value="p.id">
                            {{ p.name }} · {{ p.download_mbps }}/{{ p.upload_mbps }} Mbps · {{ fmtMoney(p.price) }} / {{ label(p.billing_cycle) }}{{ p.visibility === 'hidden' ? ' · reseller only' : '' }}
                        </option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">My package name</label>
                    <input v-model="form.name" required placeholder="e.g. Home 20 Mbps" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input v-model="form.code" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">My price per cycle ({{ cur() }})</label>
                    <input v-model="form.price" type="number" :min="base ? base.price : 0" :step="moneyStep()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs">
                    <div class="text-slate-500">Company price</div>
                    <div class="text-sm font-semibold text-slate-700">{{ base ? money(base.price) : '—' }}</div>
                </div>
                <div class="rounded-md border px-3 py-1.5 text-xs" :class="margin !== null && margin < 0 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'">
                    <div class="text-slate-500">My earning per cycle</div>
                    <div class="text-sm font-semibold" :class="margin !== null && margin < 0 ? 'text-red-600' : 'text-emerald-700'">{{ margin !== null ? money(margin) : '—' }}</div>
                </div>
                <div v-if="base" class="col-span-2 text-xs text-slate-500 md:col-span-4">
                    From the company package: {{ base.download_mbps }}/{{ base.upload_mbps }} Mbps, {{ label(base.billing_cycle) }}, validity {{ base.validity_days }} days,
                    installation {{ money(base.installation_fee) }}, activation {{ money(base.activation_fee) }}.
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                    <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-slate-600"><input v-model="form.is_active" type="checkbox" /> Active</label>
                </div>
                <div v-if="priceChanged" class="col-span-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 md:col-span-4">
                    Price change {{ money(originalPrice) }} → {{ money(form.price) }}: applies to the next invoices only; already issued invoices keep their price.
                    <input v-model="form.price_change_reason" placeholder="Reason for price change" class="mt-1 w-full rounded-md border border-amber-300 bg-white px-2 py-1 text-sm" />
                </div>
                <div class="col-span-2 flex items-end justify-end gap-2 md:col-span-4">
                    <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="reset">Reset</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ reviewing ? 'Accept & update' : form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filtered.length }} packages</span>
                <input v-model="search" placeholder="Search..." class="w-44 rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-56" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Package</th>
                            <th class="px-3 py-2 font-medium">Speed</th>
                            <th class="px-3 py-2 font-medium">Cycle</th>
                            <th class="px-3 py-2 text-end font-medium">Company price</th>
                            <th class="px-3 py-2 text-end font-medium">My price</th>
                            <th class="px-3 py-2 text-end font-medium">Earning</th>
                            <th class="px-3 py-2 text-end font-medium">Live conn.</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filtered" :key="row.id" class="border-b border-slate-100 align-top hover:bg-slate-50">
                            <td class="px-3 py-2">
                                {{ row.name }} <span class="text-xs text-slate-400">{{ row.code }}</span>
                                <div v-if="row.base_package" class="text-xs text-slate-400">from {{ row.base_package.name }}</div>
                                <button v-if="row.base_changes" type="button" class="mt-1 text-xs font-medium text-amber-700 hover:underline" @click="edit(row)">
                                    <i class="bi bi-arrow-repeat"></i> Company changed {{ Object.keys(row.base_changes).map((f) => FIELD_LABELS[f] || f).join(', ') }} — review
                                </button>
                            </td>
                            <td class="px-3 py-2">{{ row.download_mbps }}/{{ row.upload_mbps }} Mbps</td>
                            <td class="px-3 py-2">{{ label(row.billing_cycle) }}</td>
                            <td class="px-3 py-2 text-end">{{ row.base_price !== null ? money(row.base_price) : '—' }}</td>
                            <td class="px-3 py-2 text-end font-medium">{{ money(row.price) }}</td>
                            <td class="px-3 py-2 text-end text-emerald-700">{{ row.base_price !== null ? money(row.price - row.base_price) : '—' }}</td>
                            <td class="px-3 py-2 text-end">{{ row.active_connections }}</td>
                            <td class="px-3 py-2"><span class="rounded-full border px-2 py-0.5 text-xs" :class="statusOf(row).cls">{{ statusOf(row).text }}</span></td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i class="bi bi-pen cursor-pointer text-brand-500" title="Edit" @click="edit(row)"></i>
                                    <i class="bi bi-trash cursor-pointer text-red-500" title="Delete" @click="remove(row)"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="9" class="px-3 py-6 text-center text-slate-400">No packages yet</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>
