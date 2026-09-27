<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';
import { confirmDialog } from '../../lib/confirm';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();
const blank = () => ({ id: null, name: '', code: '', notes: '', branch_ids: [] });
const form = reactive(blank());
const regions = ref([]);
const branches = ref([]);

const load = () => axios.post('/isp/get-regions').then((r) => {
    regions.value = r.data.regions;
    branches.value = r.data.branches;
});
onMounted(load);

const regionName = (id) => regions.value.find((r) => r.id === id)?.name;
function edit(r) {
    Object.assign(form, blank(), { id: r.id, name: r.name, code: r.code || '', notes: r.notes || '', branch_ids: [...r.branch_ids] });
}
async function save() {
    try {
        toast.success((await axios.post('/isp/region', { ...form })).data.message);
        Object.assign(form, blank());
        load();
    } catch (err) {
        showError(err);
    }
}
async function remove(r) {
    if (!(await confirmDialog({ title: `Delete region ${r.name}?`, text: 'Its branches and managers are kept, just without a region.' }))) return;
    try {
        toast.success((await axios.post('/isp/delete-region', { id: r.id })).data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-1 text-base font-semibold text-slate-800">{{ form.id ? `Edit region ${form.name}` : 'Add region' }}</h1>
            <p class="mb-3 text-xs text-slate-500">
                Company → Region → Branch. Tick the branches that belong to the region (a branch moves if it was in another one).
                Give a user a region under User Entry to make them its regional manager: they can switch between, and see the company dashboard for, that region's branches only.
            </p>
            <form class="space-y-2" @submit.prevent="save">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                    <input v-model="form.name" required placeholder="Name (e.g. Dhaka Division)" :class="input" />
                    <input v-model="form.code" placeholder="Code" :class="input" />
                    <input v-model="form.notes" placeholder="Notes" :class="input" />
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 rounded-md border border-slate-200 p-2">
                    <label v-for="b in branches" :key="b.id" class="flex items-center gap-1 text-sm text-slate-600">
                        <input v-model="form.branch_ids" type="checkbox" :value="b.id" />
                        {{ b.name }}
                        <span v-if="b.region_id && b.region_id !== form.id" class="text-xs text-slate-400">({{ regionName(b.region_id) }})</span>
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white">{{ form.id ? 'Update' : 'Save' }}</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="Object.assign(form, blank())">Reset</button>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Region</th>
                        <th class="px-3 py-2 font-medium">Branches</th>
                        <th class="px-3 py-2 font-medium">Managers</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in regions" :key="r.id" class="border-b border-slate-100">
                        <td class="px-3 py-2 font-medium">{{ r.name }} <span v-if="r.code" class="text-xs text-slate-400">{{ r.code }}</span></td>
                        <td class="px-3 py-2">{{ r.branch_ids.map((id) => branches.find((b) => b.id === id)?.name).join(', ') || '—' }}</td>
                        <td class="px-3 py-2">{{ r.managers.join(', ') || '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2">
                                <i class="bi bi-pen cursor-pointer text-brand-500" @click="edit(r)"></i>
                                <i class="bi bi-trash cursor-pointer text-red-500" @click="remove(r)"></i>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!regions.length"><td colspan="4" class="px-3 py-6 text-center text-slate-400">No regions yet — every branch reports straight to the company</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
