<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const blank = () => ({ id: null, name: '', code: '', capacity: 16, location: '', latitude: '', longitude: '', description: '', is_active: true });
const form = reactive(blank());
const formArea = ref(null);
const rows = ref([]);
const areas = ref([]);
const zones = ref([]);
const zoneFilter = ref(null);
const areaFilter = ref(null);
const search = ref('');
const onlyFull = ref(false);
const saving = ref(false);

const filterAreas = computed(() => (zoneFilter.value ? areas.value.filter((a) => a.zone_id === zoneFilter.value.id) : areas.value));
const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return rows.value.filter(
        (r) =>
            (!areaFilter.value || r.area_id === areaFilter.value.id) &&
            (!zoneFilter.value || r.area?.zone_id === zoneFilter.value.id) &&
            (!onlyFull.value || (r.capacity && r.available_ports === 0)) &&
            (!t || `${r.name} ${r.code || ''} ${r.location || ''}`.toLowerCase().includes(t))
    );
});
const totals = computed(() => ({
    capacity: filtered.value.reduce((s, r) => s + Number(r.capacity || 0), 0),
    used: filtered.value.reduce((s, r) => s + Number(r.used_ports || 0), 0),
}));

function load() {
    axios.post('/isp/get-boxes').then((r) => (rows.value = r.data));
}
async function save() {
    if (!formArea.value) return toast.error('Select an area');
    saving.value = true;
    try {
        const res = await axios.post('/isp/box', { ...form, area_id: formArea.value.id });
        toast.success(res.data.message);
        Object.assign(form, blank());
        load();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
function edit(r) {
    Object.assign(form, { id: r.id, name: r.name, code: r.code || '', capacity: r.capacity, location: r.location || '', latitude: r.latitude || '', longitude: r.longitude || '', description: r.description || '', is_active: r.is_active });
    formArea.value = areas.value.find((a) => a.id === r.area_id) || null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
async function remove(r) {
    if (!(await confirmDialog({ title: `Delete box ${r.name}?` }))) return;
    try {
        const res = await axios.post('/isp/delete-box', { id: r.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
function useMyLocation() {
    if (!navigator.geolocation) return toast.error('Location is not available in this browser');
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.latitude = pos.coords.latitude.toFixed(7);
            form.longitude = pos.coords.longitude.toFixed(7);
        },
        () => toast.error('Could not read your location')
    );
}
function usageClass(r) {
    if (!r.capacity) return 'bg-slate-300';
    const pct = r.used_ports / r.capacity;
    return pct >= 1 ? 'bg-red-500' : pct >= 0.8 ? 'bg-amber-500' : 'bg-emerald-500';
}
onMounted(async () => {
    load();
    const [a, z] = await Promise.all([axios.post('/get-area'), axios.post('/isp/get-zones')]);
    areas.value = a.data.map((x) => ({ ...x, display_name: x.zone?.name ? `${x.name} — ${x.zone.name}` : x.name }));
    zones.value = z.data;
    const areaId = Number(new URLSearchParams(window.location.search).get('areaId'));
    if (areaId) areaFilter.value = areas.value.find((x) => x.id === areaId) || null;
});
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ form.id ? `Edit box: ${form.name}` : 'Box Entry' }}</h1>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-6" @submit.prevent="save">
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                    <SearchSelect :options="areas" v-model="formArea" label="display_name" placeholder="Select area" />
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Box name</label>
                    <input v-model="form.name" required placeholder="e.g. Box-MIR-001" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input v-model="form.code" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Capacity (ports)</label>
                    <input v-model="form.capacity" type="number" min="0" title="0 = unlimited" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Location / landmark</label>
                    <input v-model="form.location" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Latitude</label>
                    <input v-model="form.latitude" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 flex items-center justify-between text-xs font-medium text-slate-600">Longitude <button type="button" class="font-normal text-brand-600 hover:underline" @click="useMyLocation"><i class="bi bi-crosshair"></i> My location</button></label>
                    <input v-model="form.longitude" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                    <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="col-span-2 flex items-end gap-3 md:col-span-6">
                    <label class="flex items-center gap-2 pb-1.5 text-sm text-slate-600"><input v-model="form.is_active" type="checkbox" /> Active</label>
                    <button type="button" class="ml-auto rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="Object.assign(form, blank()); formArea = null">Reset</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filtered.length }} boxes · {{ totals.used }}/{{ totals.capacity }} ports used · {{ Math.max(0, totals.capacity - totals.used) }} free</span>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-1.5 text-sm text-slate-600"><input v-model="onlyFull" type="checkbox" /> Full only</label>
                    <div class="w-44"><SearchSelect :options="zones" v-model="zoneFilter" label="name" placeholder="All zones" @update:model-value="areaFilter = null" /></div>
                    <div class="w-44"><SearchSelect :options="filterAreas" v-model="areaFilter" label="name" placeholder="All areas" /></div>
                    <input v-model="search" placeholder="Search..." class="w-48 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Box</th>
                            <th class="px-3 py-2 font-medium">Area / Zone</th>
                            <th class="px-3 py-2 font-medium">Location</th>
                            <th class="w-56 px-3 py-2 font-medium">Ports</th>
                            <th class="px-3 py-2 text-right font-medium">Customers</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in filtered" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium text-slate-800">{{ r.name }} <span class="text-xs font-normal text-slate-400">{{ r.code }}</span></td>
                            <td class="px-3 py-2">{{ r.area?.name }}<div class="text-xs text-slate-400">{{ r.area?.zone?.name }}</div></td>
                            <td class="px-3 py-2 text-slate-500">
                                {{ r.location }}
                                <a v-if="r.latitude && r.longitude" :href="`https://www.google.com/maps?q=${r.latitude},${r.longitude}`" target="_blank" rel="noopener" class="ml-1 text-brand-600" title="Open in Google Maps"><i class="bi bi-geo-alt-fill"></i></a>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2 text-xs text-slate-500">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded bg-slate-100">
                                        <div class="h-full" :class="usageClass(r)" :style="{ width: r.capacity ? Math.min(100, (r.used_ports / r.capacity) * 100) + '%' : '0%' }"></div>
                                    </div>
                                    <span class="whitespace-nowrap">{{ r.capacity ? `${r.used_ports}/${r.capacity}` : `${r.used_ports} · no limit` }}</span>
                                </div>
                                <div v-if="r.capacity" class="text-[11px]" :class="r.available_ports === 0 ? 'font-medium text-red-600' : 'text-slate-400'">{{ r.available_ports === 0 ? 'Full' : `${r.available_ports} free` }}</div>
                            </td>
                            <td class="px-3 py-2 text-right">{{ r.customers_count }}</td>
                            <td class="px-3 py-2"><span :class="r.is_active ? 'text-emerald-600' : 'text-slate-400'">{{ r.is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i class="bi bi-pen cursor-pointer text-brand-500" title="Edit" @click="edit(r)"></i>
                                    <i class="bi bi-trash cursor-pointer text-red-500" title="Delete" @click="remove(r)"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="7" class="px-3 py-6 text-center text-slate-400">No boxes found</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
