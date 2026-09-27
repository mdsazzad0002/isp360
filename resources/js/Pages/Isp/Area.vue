<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const blank = () => ({ id: null, name: '', code: '', description: '' });
const form = reactive(blank());
const formZone = ref(null);
const rows = ref([]);
const zones = ref([]);
const zoneFilter = ref(null);
const search = ref('');
const saving = ref(false);

const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return rows.value.filter((r) => (!zoneFilter.value || r.zone_id === zoneFilter.value.id) && (!t || `${r.name} ${r.code || ''}`.toLowerCase().includes(t)));
});

function load() {
    axios.post('/get-area').then((r) => (rows.value = r.data));
}
async function save() {
    if (!formZone.value) return toast.error('Select a zone');
    saving.value = true;
    try {
        const payload = { name: form.name, code: form.code || null, description: form.description || null, zone_id: formZone.value.id };
        if (form.id) payload.id = form.id;
        const res = await axios.post(form.id ? '/update-area' : '/area', payload);
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
    Object.assign(form, { id: r.id, name: r.name, code: r.code || '', description: r.description || '' });
    formZone.value = zones.value.find((z) => z.id === r.zone_id) || null;
}
async function remove(r) {
    if (!(await confirmDialog({ title: `Delete area ${r.name}?` }))) return;
    try {
        const res = await axios.post('/delete-area', { id: r.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
onMounted(async () => {
    load();
    const res = await axios.post('/isp/get-zones');
    zones.value = res.data;
    const zoneId = Number(new URLSearchParams(window.location.search).get('zoneId'));
    if (zoneId) zoneFilter.value = zones.value.find((z) => z.id === zoneId) || null;
});
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ form.id ? `Edit area: ${form.name}` : 'Area Entry' }}</h1>
            <form class="grid grid-cols-1 gap-3 md:grid-cols-12" @submit.prevent="save">
                <div class="md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Zone</label>
                    <SearchSelect :options="zones" v-model="formZone" label="name" placeholder="Select zone" />
                </div>
                <div class="md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Area name</label>
                    <input v-model="form.name" required placeholder="e.g. Mirpur Area" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input v-model="form.code" placeholder="MIR" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                    <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="flex items-end justify-end gap-2 md:col-span-2">
                    <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="Object.assign(form, blank()); formZone = null">Reset</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filtered.length }} areas</span>
                <div class="flex items-center gap-3">
                    <div class="w-52"><SearchSelect :options="zones" v-model="zoneFilter" label="name" placeholder="All zones" /></div>
                    <input v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Area</th>
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 font-medium">Zone</th>
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 text-end font-medium">Boxes</th>
                            <th class="px-3 py-2 text-end font-medium">Customers</th>
                            <th class="px-3 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in filtered" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium text-slate-800">{{ r.name }}</td>
                            <td class="px-3 py-2">{{ r.code }}</td>
                            <td class="px-3 py-2">{{ r.zone?.name || '—' }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ r.description }}</td>
                            <td class="px-3 py-2 text-end"><Link :href="`/isp/boxes?areaId=${r.id}`" class="text-brand-600 hover:underline">{{ r.boxes_count }}</Link></td>
                            <td class="px-3 py-2 text-end">{{ r.customers_count }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i class="bi bi-pen cursor-pointer text-brand-500" title="Edit" @click="edit(r)"></i>
                                    <i class="bi bi-trash cursor-pointer text-red-500" title="Delete" @click="remove(r)"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="7" class="px-3 py-6 text-center text-slate-400">No areas found</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
