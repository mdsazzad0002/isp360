<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import * as XLSX from 'xlsx';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { useCan } from '../../../lib/can';
import { resizeImageFile } from '../../../lib/imageResize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    currentBranchId: { type: [String, Number], required: true },
    showBranchField: { type: Boolean, default: false },
});

const toast = useToast();
const can = useCan();
const page = usePage();
const canUserSwitch = computed(() => !!page.props.canUserSwitch);
const currentUserId = computed(() => page.props.auth?.user?.id);

function emptyForm() {
    return { id: '', name: '', email: '', phone: '', role: '', username: '', password: '', status: 'a', is_employee: false, image: '', branch_id: props.currentBranchId, switchable_branches: [] };
}

const form = reactive(emptyForm());
const rows = ref([]);
const roles = ref([]);
const imageSrc = ref('/noImage.jpg');
const onProgress = ref(false);
const showPassword = ref(false);

function load() {
    axios.post('/get-user').then((res) => {
        rows.value = res.data;
    });
}

function emptySearch() {
    return { keyword: '', role: '', branch_id: '', status: '' };
}
const search = reactive(emptySearch());

function resetSearch() {
    Object.assign(search, emptySearch());
}

const filteredRows = computed(() => {
    const keyword = search.keyword.trim().toLowerCase();
    return rows.value.filter((row) => {
        if (keyword) {
            const haystack = [row.code, row.name, row.username, row.email, row.phone].join(' ').toLowerCase();
            if (!haystack.includes(keyword)) return false;
        }
        if (search.role && row.role !== search.role) return false;
        if (search.branch_id && String(row.branch_id) !== String(search.branch_id)) return false;
        if (search.status && row.status !== search.status) return false;
        return true;
    });
});

function exportExcel() {
    const data = filteredRows.value.map((row, index) => ({
        Sl: index + 1,
        Code: row.code,
        Name: row.name,
        Username: row.username,
        Email: row.email,
        Mobile: row.phone,
        Role: row.role,
        Branch: row.branch?.name ?? '',
        Status: row.status === 'a' ? 'Active' : 'Deactive',
    }));

    const worksheet = XLSX.utils.json_to_sheet(data);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'User List');
    XLSX.writeFile(workbook, `user-list-${todayStr()}.xlsx`);
}

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function loadRoles() {
    axios.post('/get-role').then((res) => {
        roles.value = res.data;
    });
}

function resetForm() {
    Object.assign(form, emptyForm());
    imageSrc.value = '/noImage.jpg';
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-user' : '/user';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { ...form, switchable_branches: form.switchable_branches.join(',') });
        toast.success(res.data.message);
        resetForm();
        load();
    } catch (err) {
        onProgress.value = false;
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    }
}

function editRow(row) {
    Object.assign(form, {
        id: row.id,
        name: row.name,
        email: row.email,
        phone: row.phone,
        role: row.role,
        username: row.username,
        password: '',
        status: row.status,
        is_employee: !!row.is_employee,
        image: row.image,
        branch_id: row.branch_id,
        switchable_branches: row.switchable_branches
            ? String(row.switchable_branches).split(',').map((id) => parseInt(id, 10)).filter(Boolean)
            : [],
    });
    imageSrc.value = row.image ? '/' + row.image : '/noImage.jpg';
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-user', { id });
    if (res.data.status) {
        toast.success(res.data.message);
        load();
    }
}

async function onImageChange(e) {
    const file = e.target.files[0];
    if (!file) {
        e.target.value = '';
        return;
    }
    const { file: resized, dataUrl } = await resizeImageFile(file, 150, 150);
    imageSrc.value = dataUrl;
    form.image = resized;
}

onMounted(() => {
    load();
    loadRoles();
});
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">User Entry</h1>
            <form v-if="can('entry') || can('update')" @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Email</label>
                        <input type="email" autocomplete="off" v-model="form.email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Mobile</label>
                        <input type="text" autocomplete="off" v-model="form.phone" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div v-if="showBranchField">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
                        <select v-model="form.branch_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div v-if="showBranchField">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Switchable Branches</label>
                        <div class="max-h-32 space-y-1 overflow-y-auto rounded-md border border-slate-300 p-2">
                            <label v-for="b in branches" :key="b.id" class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" :value="b.id" v-model="form.switchable_branches" />
                                {{ b.name }}
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Leave all unchecked to allow switching to every branch.</p>
                    </div>
                </div>
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Role</label>
                        <select v-model="form.role" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option v-if="form.role != 'Superadmin'" value="">Select Role</option>
                            <option v-if="form.role == 'Superadmin'" value="Superadmin">Super Admin</option>
                            <template v-if="form.role != 'Superadmin'">
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Username</label>
                        <input type="text" autocomplete="off" v-model="form.username" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Password</label>
                        <div class="relative">
                            <input :type="showPassword ? 'text' : 'password'" autocomplete="off" v-model="form.password" class="w-full rounded-md border border-slate-300 px-3 py-1.5 pr-9 text-sm" />
                            <i class="bi absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-400" :class="showPassword ? 'bi-eye-slash' : 'bi-eye'" @click="showPassword = !showPassword"></i>
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" true-value="a" false-value="p" v-model="form.status" />
                                IsActive
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" v-model="form.is_employee" />
                                Is Employee
                            </label>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                            <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ form.id == '' ? 'Save' : 'Update' }}
                            </button>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <p class="mb-1 text-xs text-red-500">(150 x 150) PX</p>
                    <img :src="imageSrc" class="mb-2 h-24 w-24 rounded-md border border-slate-200 object-cover" />
                    <label class="mb-1 block text-xs font-medium text-slate-600">Upload Image</label>
                    <input type="file" @change="onImageChange" class="w-full text-xs" />
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-4 py-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Keyword</label>
                    <input type="text" v-model="search.keyword" placeholder="Code, name, username, email, mobile" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div class="w-40">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Role</label>
                    <select v-model="search.role" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">All</option>
                        <option value="Superadmin">Super Admin</option>
                        <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                    </select>
                </div>
                <div v-if="showBranchField" class="w-40">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
                    <select v-model="search.branch_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">All</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
                <div class="w-36">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                    <select v-model="search.status" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">All</option>
                        <option value="a">Active</option>
                        <option value="p">Deactive</option>
                    </select>
                </div>
                <button type="button" @click="resetSearch" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Clear</button>
                <div class="ml-auto flex items-center gap-3">
                    <span class="text-sm text-slate-500">{{ filteredRows.length }} record{{ filteredRows.length === 1 ? '' : 's' }} found</span>
                    <button type="button" @click="exportExcel" title="Export Excel" class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-50 hover:text-emerald-600">
                        <i class="bi bi-file-earmark-excel text-base"></i> Excel
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Code</th>
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Username</th>
                            <th class="px-2 py-2 font-medium">Email</th>
                            <th class="px-2 py-2 font-medium">Mobile</th>
                            <th class="px-2 py-2 font-medium">Role</th>
                            <th class="px-2 py-2 font-medium">Branch</th>
                            <th class="px-2 py-2 font-medium">Employee</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.code }}</td>
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5">{{ row.username }}</td>
                            <td class="px-2 py-1.5">{{ row.email }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="px-2 py-1.5">{{ row.role }}</td>
                            <td class="px-2 py-1.5">{{ row.branch?.name }}</td>
                            <td class="px-2 py-1.5">{{ row.is_employee ? 'Yes' : 'No' }}</td>
                            <td class="px-2 py-1.5">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="row.status === 'a' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ row.status === 'a' ? 'Active' : 'Deactive' }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="flex justify-end gap-3">
                                    <a v-if="canUserSwitch && row.id !== currentUserId" :href="`/user/${row.id}/login-as`" title="Login As User" class="text-indigo-500">
                                        <i class="bi bi-box-arrow-in-right"></i>
                                    </a>
                                    <i v-if="can('update')" @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i v-if="can('delete') && row.role !== 'Superadmin'" @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
