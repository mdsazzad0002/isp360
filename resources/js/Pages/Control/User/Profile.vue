<script setup>
import { reactive, ref, computed } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { resizeImageFile } from '../../../lib/imageResize';
import TwoFactorCard from '../../../Components/TwoFactorCard.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    user: { type: Object, required: true },
});

const toast = useToast();

const user = reactive({ ...props.user, password: '' });
const imageSrc = ref(props.user.image ? '/' + props.user.image : '/nouser.png');
const onProgress = ref(false);
const activeTab = ref('info');
let imageFile = null;

const memberSince = computed(() => {
    if (!user.created_at) return '—';
    return new Date(user.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
});

async function onImageChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    const { file: resized, dataUrl } = await resizeImageFile(file, 150, 150);
    imageSrc.value = dataUrl;
    imageFile = resized;
}

async function updateUser() {
    onProgress.value = true;
    const formdata = new FormData();
    formdata.append('id', user.id);
    formdata.append('name', user.name);
    formdata.append('username', user.username);
    formdata.append('email', user.email ?? '');
    formdata.append('role', user.role);
    formdata.append('phone', user.phone ?? '');
    formdata.append('password', user.password ?? '');
    if (imageFile) formdata.append('image', imageFile);

    try {
        const res = await axios.post('/update-user', formdata);
        if (res.data.status) {
            toast.success(res.data.message);
            user.password = '';
        }
    } catch (err) {
        if (err.response?.status === 422) {
            Object.values(err.response.data.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        }
    } finally {
        onProgress.value = false;
    }
}
</script>

<template>
    <div class="mx-auto p-4">
        <form @submit.prevent="updateUser" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col items-center gap-4 border-b border-slate-100 bg-slate-50 p-6 sm:flex-row">
                <img :src="imageSrc" alt="Profile" class="h-24 w-24 shrink-0 rounded-full border-4 border-white object-cover shadow" />
                <div class="min-w-0 flex-1 text-center sm:text-start">
                    <div class="text-base font-bold text-slate-800">{{ user.name }}</div>
                    <span class="mt-1 inline-block rounded-full bg-brand-500/10 px-3 py-0.5 text-xs font-semibold capitalize text-brand-600">{{ user.role }}</span>
                </div>
                <label class="cursor-pointer rounded-md border border-brand-500 px-3 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50">
                    <i class="bi bi-camera me-1"></i> Change Photo
                    <input type="file" accept="image/*" class="hidden" @change="onImageChange" />
                </label>
            </div>

            <nav class="flex w-full border-b border-slate-200 bg-white">
                <button
                    type="button"
                    @click="activeTab = 'info'"
                    class="flex flex-1 items-center justify-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="activeTab === 'info' ? 'border-brand-500 text-brand-600' : 'border-transparent text-slate-500 hover:bg-slate-50'"
                >
                    <i class="bi bi-person"></i> Profile
                </button>
                <button
                    type="button"
                    @click="activeTab = 'security'"
                    class="flex flex-1 items-center justify-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="activeTab === 'security' ? 'border-brand-500 text-brand-600' : 'border-transparent text-slate-500 hover:bg-slate-50'"
                >
                    <i class="bi bi-shield-lock"></i> Security
                </button>
                <button
                    type="button"
                    @click="activeTab = 'account'"
                    class="flex flex-1 items-center justify-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="activeTab === 'account' ? 'border-brand-500 text-brand-600' : 'border-transparent text-slate-500 hover:bg-slate-50'"
                >
                    <i class="bi bi-info-circle"></i> Account
                </button>
            </nav>

            <div class="p-6">
                <div v-if="activeTab === 'info'" class="max-w-sm space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input type="text" v-model="user.name" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Username</label>
                        <input type="text" v-model="user.username" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Email</label>
                        <input type="email" v-model="user.email" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Phone</label>
                        <input type="text" v-model="user.phone" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                </div>

                <div v-if="activeTab === 'security'" class="max-w-xs">
                    <label class="mb-1 block text-xs font-medium text-slate-600">New Password</label>
                    <input type="password" autocomplete="new-password" v-model="user.password" placeholder="Leave blank to keep current password" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <p class="mt-1 text-xs text-slate-400">Only fill this in if you want to change your password.</p>
                </div>

                <div v-if="activeTab === 'account'" class="divide-y divide-slate-100">
                    <div class="flex items-center py-3">
                        <div class="w-36 shrink-0 text-xs font-semibold uppercase tracking-wide text-slate-400">Role</div>
                        <div class="font-semibold capitalize text-slate-800">{{ user.role }}</div>
                    </div>
                    <div class="flex items-center py-3">
                        <div class="w-36 shrink-0 text-xs font-semibold uppercase tracking-wide text-slate-400">Member Since</div>
                        <div class="font-semibold text-slate-800">{{ memberSince }}</div>
                    </div>
                </div>

                <div class="mt-6 text-end">
                    <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-5 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                        Save Changes
                    </button>
                </div>
            </div>
        </form>
        <TwoFactorCard class="mt-4" base="/two-factor" />
    </div>
</template>
