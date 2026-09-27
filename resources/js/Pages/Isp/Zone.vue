<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const blank = () => ({ id: null, name: '', code: '', description: '', is_active: true });
const form = reactive(blank());
const rows = ref([]);
const search = ref('');
const saving = ref(false);

const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return t ? rows.value.filter((r) => `${r.name} ${r.code || ''}`.toLowerCase().includes(t)) : rows.value;
});

function load() {
    axios.post('/isp/get-zones').then((r) => (rows.value = r.data));
}
async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/zone', { ...form });
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
    Object.assign(form, { id: r.id, name: r.name, code: r.code || '', description: r.description || '', is_active: r.is_active });
}
async function remove(r) {
    if (!(await confirmDialog({ title: `Delete zone ${r.name}?` }))) return;
    try {
        const res = await axios.post('/isp/delete-zone', { id: r.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
onMounted(load);
</script>

<template>
    <div class="p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">{{ form.id ? `Edit zone: ${form.name}` : 'Zone Entry' }}</h1>
            <form class="grid grid-cols-1 gap-3 md:grid-cols-12" @submit.prevent="save">
                <div class="md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Zone name</label>
                    <input v-model="form.name" required placeholder="e.g. Dhaka Zone" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input v-model="form.code" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="md:col-span-4">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                    <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="flex items-end gap-3 md:col-span-3">
                    <label class="flex items-center gap-2 pb-1.5 text-sm text-slate-600"><input v-model="form.is_active" type="checkbox" /> Active</label>
                    <button type="button" class="ml-auto rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="Object.assign(form, blank())">Reset</button>
                    <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 p-3">
                <span class="text-sm text-slate-500">{{ filtered.length }} zones</span>
                <input v-model="search" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Zone</th>
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 text-right font-medium">Areas</th>
                            <th class="px-3 py-2 text-right font-medium">Customers</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in filtered" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium text-slate-800">{{ r.name }}</td>
                            <td class="px-3 py-2">{{ r.code }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ r.description }}</td>
                            <td class="px-3 py-2 text-right"><Link :href="`/isp/areas?zoneId=${r.id}`" class="text-brand-600 hover:underline">{{ r.areas_count }}</Link></td>
                            <td class="px-3 py-2 text-right">{{ r.customers_count }}</td>
                            <td class="px-3 py-2"><span :class="r.is_active ? 'text-emerald-600' : 'text-slate-400'">{{ r.is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3">
                                    <i class="bi bi-pen cursor-pointer text-brand-500" title="Edit" @click="edit(r)"></i>
                                    <i class="bi bi-trash cursor-pointer text-red-500" title="Delete" @click="remove(r)"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="7" class="px-3 py-6 text-center text-slate-400">No zones yet</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
