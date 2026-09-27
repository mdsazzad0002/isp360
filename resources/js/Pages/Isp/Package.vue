<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Modal from '../../Components/Modal.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { money, fmtDate, label, useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

function blank() {
    return { id: null, name: '', code: '', download_mbps: '', upload_mbps: '', price: '', billing_cycle: 'monthly', validity_days: 30, installation_fee: 0, activation_fee: 0, network_profile: '', description: '', is_active: true, visibility: 'universal', reseller_id: null, price_change_reason: '' };
}
const form = reactive(blank());
const rows = ref([]);
const search = ref('');
const saving = ref(false);
const originalPrice = ref(null);
const history = reactive({ show: false, pkg: null, rows: [] });

const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return t ? rows.value.filter((r) => `${r.name} ${r.code || ''}`.toLowerCase().includes(t)) : rows.value;
});
const priceChanged = computed(() => form.id && originalPrice.value !== null && Number(form.price) !== Number(originalPrice.value));

function load() {
    axios.post('/isp/get-packages', { owner: 'company' }).then((r) => (rows.value = r.data));
}

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/package', { ...form });
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
}

function edit(row) {
    Object.assign(form, blank(), {
        id: row.id, name: row.name, code: row.code || '', download_mbps: row.download_mbps, upload_mbps: row.upload_mbps, price: Number(row.price),
        billing_cycle: row.billing_cycle, validity_days: row.validity_days, installation_fee: Number(row.installation_fee), activation_fee: Number(row.activation_fee),
        network_profile: row.network_profile || '', description: row.description || '', is_active: row.is_active,
        visibility: row.visibility || 'universal', reseller_id: row.reseller_id,
    });
    originalPrice.value = Number(row.price);
    window.scrollTo({ top: 0, behavior: 'smooth' });
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
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ form.id ? `Edit package: ${form.name}` : 'Package Entry' }}</h1>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-6" @submit.prevent="save">
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Package name</label>
                    <input v-model="form.name" required placeholder="e.g. 20 Mbps Home" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input v-model="form.code" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Download (Mbps)</label>
                    <input v-model="form.download_mbps" type="number" min="0" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Upload (Mbps)</label>
                    <input v-model="form.upload_mbps" type="number" min="0" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Billing cycle</label>
                    <select v-model="form.billing_cycle" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="half_yearly">Half-yearly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Price per cycle (Tk)</label>
                    <input v-model="form.price" type="number" min="0" step="0.01" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Installation fee</label>
                    <input v-model="form.installation_fee" type="number" min="0" step="0.01" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Activation fee</label>
                    <input v-model="form.activation_fee" type="number" min="0" step="0.01" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Validity (days)</label>
                    <input v-model="form.validity_days" type="number" min="1" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Router profile</label>
                    <input v-model="form.network_profile" placeholder="MikroTik/RADIUS profile" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div v-if="!form.reseller_id">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Visibility</label>
                    <select v-model="form.visibility" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" title="Universal: any customer. Hidden: wholesale base that resellers customize and resell.">
                        <option value="universal">Universal (everyone)</option>
                        <option value="hidden">Hidden (reseller base)</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-slate-600"><input v-model="form.is_active" type="checkbox" /> Active</label>
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                    <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div v-if="priceChanged" class="col-span-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 md:col-span-6">
                    Price change {{ money(originalPrice) }} → {{ money(form.price) }}: applies to the next invoices only; already issued invoices keep their price.
                    <input v-model="form.price_change_reason" placeholder="Reason for price change" class="mt-1 w-full rounded-md border border-amber-300 bg-white px-2 py-1 text-sm" />
                </div>
                <div class="col-span-2 flex items-end justify-end gap-2">
                    <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="reset">Reset</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filtered.length }} packages</span>
                <input v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Package</th>
                            <th class="px-3 py-2 font-medium">Visibility</th>
                            <th class="px-3 py-2 font-medium">Speed</th>
                            <th class="px-3 py-2 font-medium">Cycle</th>
                            <th class="px-3 py-2 text-right font-medium">Price</th>
                            <th class="px-3 py-2 text-right font-medium">Install / Activation</th>
                            <th class="px-3 py-2 text-right font-medium">Live connections</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filtered" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2">{{ row.name }} <span class="text-xs text-slate-400">{{ row.code }}</span></td>
                            <td class="px-3 py-2">
                                <span v-if="row.visibility === 'hidden'" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Resellers customize this package; not for reseller customers as-is">Hidden</span>
                                <span v-else class="text-xs text-slate-500">Universal</span>
                                <a v-if="row.reseller_copies_count" href="/isp/reseller-packages" class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700 hover:underline">{{ row.reseller_copies_count }} reseller cop{{ row.reseller_copies_count > 1 ? 'ies' : 'y' }}</a>
                            </td>
                            <td class="px-3 py-2">{{ row.download_mbps }}/{{ row.upload_mbps }} Mbps</td>
                            <td class="px-3 py-2">{{ label(row.billing_cycle) }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ money(row.price) }}</td>
                            <td class="px-3 py-2 text-right">{{ money(row.installation_fee) }} / {{ money(row.activation_fee) }}</td>
                            <td class="px-3 py-2 text-right">{{ row.active_connections }}</td>
                            <td class="px-3 py-2"><span :class="row.is_active ? 'text-emerald-600' : 'text-slate-400'">{{ row.is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i class="bi bi-clock-history cursor-pointer text-slate-500" title="Price history" @click="showHistory(row)"></i>
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
