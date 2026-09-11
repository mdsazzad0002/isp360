<script setup>
import { reactive, ref, onMounted } from 'vue';
import axios from 'axios';
import SearchSelect from '../../../Components/SearchSelect.vue';
import Pagination from '../../../Components/Pagination.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { resizeImageFile } from '../../../lib/imageResize';

defineOptions({ layout: AppLayout });

const toast = useToast();

function emptyForm() {
    return {
        id: '',
        name: '',
        phone: '',
        email: '',
        username: '',
        password: '',
        address: '',
        status: 'a',
        image: '',
    };
}

const form = reactive(emptyForm());
const areas = ref([]);
const selectedArea = ref(null);
const rows = ref([]);
const currentPage = ref(1);
const perPage = 10;
const totalPages = ref(0);
const filter = ref('');
const imageSrc = ref('/noImage.jpg');
const onProgress = ref(false);
let filterTimeout = null;

function getAreas() {
    axios.post('/get-area').then((res) => {
        areas.value = res.data;
    });
}

function load() {
    axios
        .post('/get-reseller', {
            page: currentPage.value,
            per_page: perPage,
            search: filter.value,
        })
        .then((res) => {
            totalPages.value = res.data.last_page;
            rows.value = res.data.data.map((item, index) => ({
                ...item,
                sl: (res.data.current_page - 1) * perPage + index + 1,
            }));
        });
}

function onFilterInput() {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        currentPage.value = 1;
        load();
    }, 300);
}

function changePage(p) {
    currentPage.value = p;
    load();
}

function resetForm() {
    Object.assign(form, emptyForm());
    selectedArea.value = null;
    imageSrc.value = '/noImage.jpg';
    onProgress.value = false;
}

async function saveData() {
    const url = form.id != '' ? '/update-reseller' : '/reseller';
    onProgress.value = true;
    try {
        const res = await axios.post(url, { ...form, area_id: selectedArea.value ? selectedArea.value.id : '' });
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
        phone: row.phone,
        email: row.email,
        username: row.username,
        password: '',
        address: row.address,
        status: row.status,
        image: row.image,
    });
    selectedArea.value = { id: row.area_id, name: row.area?.name };
    imageSrc.value = row.image ? '/' + row.image : '/noImage.jpg';
}

async function deleteRow(id) {
    if (!confirm('Are you sure?')) return;
    const res = await axios.post('/delete-reseller', { id });
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
    getAreas();
    load();
});
</script>

<template>
    <div class="mx-auto p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">Reseller Entry</h1>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Mobile</label>
                        <input type="text" autocomplete="off" v-model="form.phone" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Email <span class="font-normal text-slate-400">(Optional)</span></label>
                        <input type="email" autocomplete="off" v-model="form.email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area <span class="font-normal text-slate-400">(Optional)</span></label>
                        <SearchSelect :options="areas" v-model="selectedArea" label="name" placeholder="Select area" />
                    </div>
                </div>
                <div class="space-y-3 md:col-span-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Address</label>
                        <input type="text" autocomplete="off" v-model="form.address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Portal Username</label>
                            <input type="text" autocomplete="off" v-model="form.username" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="flex-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Portal Password</label>
                            <input
                                type="password"
                                autocomplete="new-password"
                                v-model="form.password"
                                :placeholder="form.id == '' ? '' : 'Leave blank to keep current'"
                                class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                            />
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" true-value="a" false-value="p" v-model="form.status" />
                            IsActive
                        </label>
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

        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                    <input type="text" v-model="filter" @input="onFilterInput" placeholder="Search..." class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Sl</th>
                            <th class="px-2 py-2 font-medium">Code</th>
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Username</th>
                            <th class="px-2 py-2 font-medium">Mobile</th>
                            <th class="px-2 py-2 font-medium">Area</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.sl }}</td>
                            <td class="px-2 py-1.5">{{ row.code }}</td>
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5">{{ row.username }}</td>
                            <td class="px-2 py-1.5">{{ row.phone }}</td>
                            <td class="px-2 py-1.5">{{ row.area?.name }}</td>
                            <td class="px-2 py-1.5">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="row.status === 'a' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ row.status === 'a' ? 'Active' : 'Deactive' }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="flex justify-end gap-3">
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="currentPage" :total-pages="totalPages" @change="changePage" />
        </div>
    </div>
</template>
