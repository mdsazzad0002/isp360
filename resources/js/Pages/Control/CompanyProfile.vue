<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { resizeImageFile } from '../../lib/imageResize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    company: { type: Object, required: true },
});

const toast = useToast();

const form = reactive({
    name: props.company.name,
    title: props.company.title,
    phone: props.company.phone,
    email: props.company.email,
    address: props.company.address,
    url: props.company.url,
});

let logoFile = null;
let logoRemoved = false;
let faviconFile = null;
let faviconRemoved = false;

const logoSrc = ref(props.company.logo ? '/' + props.company.logo : '/noImage.jpg');
const faviconSrc = ref(props.company.favicon ? '/' + props.company.favicon : '/noImage.jpg');

async function onLogoChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    const { file: resized, dataUrl } = await resizeImageFile(file, 150, 150);
    logoSrc.value = dataUrl;
    logoFile = resized;
    logoRemoved = false;
}

async function onFaviconChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    const { file: resized, dataUrl } = await resizeImageFile(file, 100, 100);
    faviconSrc.value = dataUrl;
    faviconFile = resized;
    faviconRemoved = false;
}

function removeLogo() {
    logoSrc.value = '/noImage.jpg';
    logoFile = null;
    logoRemoved = true;
}

function removeFavicon() {
    faviconSrc.value = '/noImage.jpg';
    faviconFile = null;
    faviconRemoved = true;
}

async function updateCompanyProfile() {
    const formdata = new FormData();
    formdata.append('name', form.name);
    formdata.append('title', form.title);
    formdata.append('phone', form.phone);
    formdata.append('email', form.email ?? '');
    formdata.append('address', form.address ?? '');
    formdata.append('url', form.url ?? '');
    formdata.append('logo', logoRemoved ? 'null' : logoFile ?? '');
    formdata.append('favicon', faviconRemoved ? 'null' : faviconFile ?? '');

    try {
        const res = await axios.post('/update-companyProfile', formdata);
        if (res.data.status) {
            toast.success(res.data.message);
        }
    } catch (err) {
        if (err.response?.status === 422) {
            Object.values(err.response.data.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        }
    }
}
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
            <div class="md:col-span-4">
                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2.5">
                        <h3 class="text-sm font-semibold text-white">Branding</h3>
                    </div>
                    <div class="space-y-6 p-4">
                        <div class="text-center">
                            <p class="mb-2 text-xs text-red-500">Logo (150 x 150) PX</p>
                            <div class="relative mx-auto w-32">
                                <img :src="logoSrc" class="h-32 w-32 rounded-md border border-dashed border-slate-300 bg-slate-50 object-contain p-1" />
                                <button type="button" @click="removeLogo" class="absolute -end-2 -top-2 h-6 w-6 rounded-full bg-red-500 text-xs text-white">X</button>
                            </div>
                            <label class="mt-2 block text-xs font-medium text-slate-600">Upload Logo</label>
                            <input type="file" @change="onLogoChange" class="w-full text-xs" />
                        </div>
                        <div class="border-t border-slate-200 pt-4 text-center">
                            <p class="mb-2 text-xs text-red-500">Favicon (100 x 100) PX</p>
                            <div class="relative mx-auto w-32">
                                <img :src="faviconSrc" class="h-32 w-32 rounded-md border border-dashed border-slate-300 bg-slate-50 object-contain p-1" />
                                <button type="button" @click="removeFavicon" class="absolute -end-2 -top-2 h-6 w-6 rounded-full bg-red-500 text-xs text-white">X</button>
                            </div>
                            <label class="mt-2 block text-xs font-medium text-slate-600">Upload Favicon</label>
                            <input type="file" @change="onFaviconChange" class="w-full text-xs" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="md:col-span-8">
                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2.5">
                        <h3 class="text-sm font-semibold text-white">Company Information</h3>
                    </div>
                    <form @submit.prevent="updateCompanyProfile" class="space-y-3 p-4">
                        <div class="grid grid-cols-4 items-center gap-2">
                            <label class="col-span-1 text-sm text-slate-600">Company Name</label>
                            <input type="text" v-model="form.name" autocomplete="off" class="col-span-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="grid grid-cols-4 items-center gap-2">
                            <label class="col-span-1 text-sm text-slate-600">Company Title</label>
                            <input type="text" v-model="form.title" autocomplete="off" class="col-span-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="grid grid-cols-4 items-center gap-2">
                            <label class="col-span-1 text-sm text-slate-600">Mobile</label>
                            <input type="text" v-model="form.phone" autocomplete="off" class="col-span-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="grid grid-cols-4 items-center gap-2">
                            <label class="col-span-1 text-sm text-slate-600">Email</label>
                            <input type="email" v-model="form.email" autocomplete="off" class="col-span-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="grid grid-cols-4 items-center gap-2">
                            <label class="col-span-1 text-sm text-slate-600">Website</label>
                            <input type="text" v-model="form.url" placeholder="www.example.com" autocomplete="off" class="col-span-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="grid grid-cols-4 items-start gap-2">
                            <label class="col-span-1 pt-1.5 text-sm text-slate-600">Address</label>
                            <textarea v-model="form.address" rows="4" class="col-span-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="rounded-md bg-gradient-to-r from-brand-500 to-brand-600 px-6 py-2 text-sm font-medium text-white shadow-sm cursor-pointer">Update Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
