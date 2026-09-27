<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import Offcanvas from '../../Components/Offcanvas.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { money, fmtDate, label, useApiError, cur, moneyStep } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const cycleDays = { monthly: 30, quarterly: 90, half_yearly: 180, yearly: 365 };

function blank() {
    return { id: null, name: '', code: '', download_mbps: '', upload_mbps: '', price: '', billing_cycle: 'monthly', validity_days: 30, installation_fee: 0, activation_fee: 0, network_profile: '', description: '', is_active: true, visibility: 'universal', reseller_id: null, price_change_reason: '', tax_mode: 'default', tax_rate_ids: [] };
}
const form = reactive(blank());
const rows = ref([]);
const search = ref('');
const statusFilter = ref('all');
const saving = ref(false);
const originalPrice = ref(null);
const panel = reactive({ show: false, mode: 'create', source: null });
const nameInput = ref(null);
const history = reactive({ show: false, pkg: null, rows: [] });

const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return rows.value.filter((r) => {
        if (statusFilter.value === 'active' && !r.is_active) return false;
        if (statusFilter.value === 'inactive' && r.is_active) return false;
        if (statusFilter.value === 'hidden' && r.visibility !== 'hidden') return false;
        return !t || `${r.name} ${r.code || ''} ${r.network_profile || ''}`.toLowerCase().includes(t);
    });
});
const stats = computed(() => ({
    total: rows.value.length,
    active: rows.value.filter((r) => r.is_active).length,
    hidden: rows.value.filter((r) => r.visibility === 'hidden').length,
    connections: rows.value.reduce((s, r) => s + Number(r.active_connections || 0), 0),
}));
const priceChanged = computed(() => form.id && originalPrice.value !== null && Number(form.price) !== Number(originalPrice.value));
const panelTitle = computed(() => ({ create: 'New package', edit: `Edit package`, clone: 'Clone package' })[panel.mode]);
const oneTimeTotal = computed(() => Number(form.installation_fee || 0) + Number(form.activation_fee || 0));

const taxRates = ref([]);
function load() {
    axios.post('/isp/get-packages', { owner: 'company' }).then((r) => (rows.value = r.data));
    axios.post('/isp/get-tax-rates').then((r) => (taxRates.value = r.data));
}
const defaultTaxNames = computed(() => taxRates.value.filter((r) => r.is_default).map((r) => `${r.name} ${Number(r.rate)}%`).join(' + ') || 'none set');

function fromRow(row) {
    return {
        name: row.name, code: row.code || '', download_mbps: row.download_mbps, upload_mbps: row.upload_mbps, price: Number(row.price),
        billing_cycle: row.billing_cycle, validity_days: row.validity_days, installation_fee: Number(row.installation_fee), activation_fee: Number(row.activation_fee),
        network_profile: row.network_profile || '', description: row.description || '', is_active: !!row.is_active,
        visibility: row.visibility || 'universal', reseller_id: row.reseller_id,
        // tax_rate_ids: null = the default rates, [] = exempt, [ids] = these rates
        tax_mode: row.tax_rate_ids == null ? 'default' : row.tax_rate_ids.length ? 'custom' : 'exempt',
        tax_rate_ids: (row.tax_rate_ids || []).map(Number),
    };
}

function openPanel(mode, row = null) {
    Object.assign(form, blank());
    originalPrice.value = null;
    panel.mode = mode;
    panel.source = row;
    if (mode === 'edit') {
        Object.assign(form, fromRow(row), { id: row.id });
        originalPrice.value = Number(row.price);
    } else if (mode === 'clone') {
        // Same settings, new record: fresh name and code so it doesn't collide with the original.
        Object.assign(form, fromRow(row), { id: null, reseller_id: null, name: uniqueCopyName(row.name), code: '' });
    }
    panel.show = true;
    nextTick(() => setTimeout(() => nameInput.value?.select(), 60));
}

function uniqueCopyName(name) {
    const taken = new Set(rows.value.map((r) => r.name.toLowerCase()));
    let candidate = `${name} (Copy)`;
    for (let i = 2; taken.has(candidate.toLowerCase()); i++) candidate = `${name} (Copy ${i})`;
    return candidate.slice(0, 100);
}

function onCycleChange() {
    form.validity_days = cycleDays[form.billing_cycle] || 30;
}

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/package', { ...form });
        toast.success(res.data.message);
        panel.show = false;
        load();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    if (!(await confirmDialog({ title: `Delete package ${row.name}?` }))) return;
    try {
        const res = await axios.post('/isp/delete-package', { id: row.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}

async function showHistory(row) {
    const res = await axios.post('/isp/get-package-history', { id: row.id });
    Object.assign(history, { show: true, pkg: row, rows: res.data });
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">Packages</h1>
                <p class="text-xs text-slate-500">Internet plans offered to customers and used as a base by resellers.</p>
            </div>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-600" @click="openPanel('create')">
                <i class="bi bi-plus-lg"></i> New package
            </button>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Total packages</div>
                <div class="mt-1 text-xl font-semibold text-slate-800">{{ stats.total }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Active</div>
                <div class="mt-1 text-xl font-semibold text-emerald-600">{{ stats.active }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Hidden (reseller base)</div>
                <div class="mt-1 text-xl font-semibold text-slate-700">{{ stats.hidden }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">Live connections</div>
                <div class="mt-1 text-xl font-semibold text-brand-600">{{ stats.connections }}</div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-3">
                <div class="flex gap-1 rounded-md bg-slate-100 p-0.5 text-xs">
                    <button
                        v-for="f in [['all', 'All'], ['active', 'Active'], ['inactive', 'Inactive'], ['hidden', 'Hidden']]"
                        :key="f[0]"
                        type="button"
                        class="rounded px-3 py-1"
                        :class="statusFilter === f[0] ? 'bg-white font-medium text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        @click="statusFilter = f[0]"
                    >
                        {{ f[1] }}
                    </button>
                </div>
                <div class="relative">
                    <i class="bi bi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input v-model="search" placeholder="Search name, code, profile..." class="w-64 rounded-md border border-slate-300 py-1.5 pl-8 pr-3 text-sm" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2 font-medium">Package</th>
                            <th class="px-3 py-2 font-medium">Speed</th>
                            <th class="px-3 py-2 text-right font-medium">Price</th>
                            <th class="px-3 py-2 text-right font-medium">Install / Activation</th>
                            <th class="px-3 py-2 font-medium">Visibility</th>
                            <th class="px-3 py-2 text-right font-medium">Live</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filtered" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :class="!row.is_active && 'opacity-60'">
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-slate-800">{{ row.name }}</div>
                                <div class="text-xs text-slate-400">
                                    <span v-if="row.code">{{ row.code }}</span>
                                    <span v-if="row.code && row.network_profile"> · </span>
                                    <span v-if="row.network_profile"><i class="bi bi-router"></i> {{ row.network_profile }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="inline-flex items-center gap-2 whitespace-nowrap text-slate-700">
                                    <span><i class="bi bi-arrow-down text-emerald-500"></i>{{ row.download_mbps }}</span>
                                    <span><i class="bi bi-arrow-up text-sky-500"></i>{{ row.upload_mbps }}</span>
                                    <span class="text-xs text-slate-400">Mbps</span>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="font-semibold text-slate-800">{{ money(row.price) }}</div>
                                <div class="text-xs text-slate-400">{{ label(row.billing_cycle) }} · {{ row.validity_days }}d</div>
                            </td>
                            <td class="px-3 py-2.5 text-right text-slate-600">{{ money(row.installation_fee) }} / {{ money(row.activation_fee) }}</td>
                            <td class="px-3 py-2.5">
                                <span v-if="row.visibility === 'hidden'" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600" title="Resellers customize this package; not for reseller customers as-is">Hidden</span>
                                <span v-else class="rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700">Universal</span>
                                <a v-if="row.reseller_copies_count" href="/isp/reseller-packages" class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700 hover:underline">{{ row.reseller_copies_count }} reseller cop{{ row.reseller_copies_count > 1 ? 'ies' : 'y' }}</a>
                            </td>
                            <td class="px-3 py-2.5 text-right font-medium text-slate-700">{{ row.active_connections }}</td>
                            <td class="px-3 py-2.5">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs" :class="row.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="row.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="rounded p-1.5 text-slate-500 hover:bg-slate-100" title="Price history" @click="showHistory(row)"><i class="bi bi-clock-history"></i></button>
                                    <button type="button" class="rounded p-1.5 text-violet-600 hover:bg-violet-50" title="Clone" @click="openPanel('clone', row)"><i class="bi bi-copy"></i></button>
                                    <button type="button" class="rounded p-1.5 text-brand-500 hover:bg-brand-50" title="Edit" @click="openPanel('edit', row)"><i class="bi bi-pen"></i></button>
                                    <button type="button" class="rounded p-1.5 text-red-500 hover:bg-red-50" title="Delete" @click="remove(row)"><i class="bi bi-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="8" class="px-3 py-10 text-center text-slate-400">
                                <i class="bi bi-box-seam mb-1 block text-2xl"></i>
                                {{ rows.length ? 'No packages match the filter' : 'No packages yet' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Offcanvas :show="panel.show" width="sm:w-[560px]" @close="panel.show = false">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-base font-bold text-slate-800">
                        <i class="bi" :class="panel.mode === 'clone' ? 'bi-copy' : panel.mode === 'edit' ? 'bi-pen' : 'bi-box-seam'"></i>
                        {{ panelTitle }}
                    </h2>
                    <p v-if="panel.mode === 'clone'" class="text-xs text-slate-500">Copying settings from <strong>{{ panel.source?.name }}</strong> — saves as a new package.</p>
                    <p v-else-if="panel.mode === 'edit'" class="text-xs text-slate-500">{{ panel.source?.name }}</p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="panel.show = false"><i class="bi bi-x-lg"></i></button>
            </div>

            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="save">
                <div class="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-5 text-sm">
                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Basic info</h3>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Package name <span class="text-red-500">*</span></label>
                                <input ref="nameInput" v-model="form.name" required maxlength="100" placeholder="e.g. 20 Mbps Home" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                                <input v-model="form.code" maxlength="50" placeholder="PKG-20" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                            <div class="col-span-3">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                                <textarea v-model="form.description" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-1.5"></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Speed &amp; network</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600"><i class="bi bi-arrow-down text-emerald-500"></i> Download (Mbps) <span class="text-red-500">*</span></label>
                                <input v-model="form.download_mbps" type="number" min="0" required class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600"><i class="bi bi-arrow-up text-sky-500"></i> Upload (Mbps) <span class="text-red-500">*</span></label>
                                <input v-model="form.upload_mbps" type="number" min="0" required class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                            <div class="col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Router profile</label>
                                <input v-model="form.network_profile" maxlength="100" placeholder="MikroTik / RADIUS profile name" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Pricing &amp; billing</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Billing cycle</label>
                                <select v-model="form.billing_cycle" class="w-full rounded-md border border-slate-300 px-3 py-1.5" @change="onCycleChange">
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="half_yearly">Half-yearly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Validity (days)</label>
                                <input v-model="form.validity_days" type="number" min="1" max="400" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                            <div class="col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Price per cycle ({{ cur() }}) <span class="text-red-500">*</span></label>
                                <input v-model="form.price" type="number" min="0" :step="moneyStep()" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-base font-semibold" />
                            </div>
                            <div v-if="priceChanged" class="col-span-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                                Price change {{ money(originalPrice) }} → {{ money(form.price) }}: applies to the next invoices only; already issued invoices keep their price.
                                <input v-model="form.price_change_reason" maxlength="255" placeholder="Reason for price change" class="mt-1 w-full rounded-md border border-amber-300 bg-white px-2 py-1 text-sm" />
                            </div>
                            <div class="col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Tax</label>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <label><input v-model="form.tax_mode" type="radio" value="default" /> Default ({{ defaultTaxNames }})</label>
                                    <label><input v-model="form.tax_mode" type="radio" value="custom" /> Choose rates</label>
                                    <label><input v-model="form.tax_mode" type="radio" value="exempt" /> Tax exempt</label>
                                </div>
                                <div v-if="form.tax_mode === 'custom'" class="mt-1 flex flex-wrap gap-3 text-sm">
                                    <label v-for="r in taxRates" :key="r.id"><input v-model="form.tax_rate_ids" type="checkbox" :value="r.id" /> {{ r.name }} {{ Number(r.rate) }}%</label>
                                    <span v-if="!taxRates.length" class="text-xs text-slate-400">Add tax rates in ISP Billing Settings first.</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">Resellers' copies of this package are taxed the same way. The fees use the default rates.</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Installation fee</label>
                                <input v-model="form.installation_fee" type="number" min="0" :step="moneyStep()" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Activation fee</label>
                                <input v-model="form.activation_fee" type="number" min="0" :step="moneyStep()" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Availability</h3>
                        <div v-if="!form.reseller_id" class="mb-3 grid grid-cols-2 gap-2">
                            <label class="cursor-pointer rounded-md border p-2.5" :class="form.visibility === 'universal' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
                                <input v-model="form.visibility" type="radio" value="universal" class="mr-1" />
                                <span class="font-medium text-slate-700">Universal</span>
                                <span class="mt-0.5 block text-xs text-slate-500">Any customer can take it.</span>
                            </label>
                            <label class="cursor-pointer rounded-md border p-2.5" :class="form.visibility === 'hidden' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
                                <input v-model="form.visibility" type="radio" value="hidden" class="mr-1" />
                                <span class="font-medium text-slate-700">Hidden</span>
                                <span class="mt-0.5 block text-xs text-slate-500">Wholesale base resellers customize.</span>
                            </label>
                        </div>
                        <label class="flex items-center gap-2 text-slate-700"><input v-model="form.is_active" type="checkbox" /> Active (available for new connections)</label>
                    </section>
                </div>

                <div class="flex items-center justify-between gap-2 border-t border-slate-200 bg-white px-5 py-3">
                    <div class="text-xs text-slate-500">
                        <span v-if="form.price !== ''"><strong class="text-slate-800">{{ money(form.price) }}</strong> / {{ label(form.billing_cycle) }}</span>
                        <span v-if="oneTimeTotal"> + {{ money(oneTimeTotal) }} one-time</span>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm" @click="panel.show = false">Cancel</button>
                        <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                            {{ saving ? 'Saving...' : panel.mode === 'edit' ? 'Update package' : panel.mode === 'clone' ? 'Create copy' : 'Save package' }}
                        </button>
                    </div>
                </div>
            </form>
        </Offcanvas>

        <Modal :show="history.show" @close="history.show = false">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">Price history — {{ history.pkg?.name }}</div>
            <div class="p-4 text-sm">
                <div v-for="h in history.rows" :key="h.id" class="flex justify-between border-b border-slate-100 py-1.5">
                    <span>{{ fmtDate(h.created_at) }} · {{ h.changed_by?.name }} <span class="text-slate-400">{{ h.reason }}</span></span>
                    <span>{{ money(h.old_price) }} → <strong>{{ money(h.new_price) }}</strong></span>
                </div>
                <div v-if="!history.rows.length" class="py-4 text-center text-slate-400">No price changes</div>
            </div>
        </Modal>
    </div>
</template>
