<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import { useToast } from '../../lib/toast';

const props = defineProps({
    customer: { type: Object, required: true },
});

const toast = useToast();
const form = reactive({
    name: props.customer.name,
    email: props.customer.email ?? '',
    phone: props.customer.phone ?? '',
    address: props.customer.address ?? '',
    password: '',
});
const onProgress = ref(false);

async function save() {
    onProgress.value = true;
    try {
        const res = await axios.post('/customer-portal/update-profile', form);
        toast.success(res.data.message);
        form.password = '';
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
    <PortalLayout :user-name="customer.name" logout-url="/customer-portal/logout">
        <h1 class="mb-4 text-lg font-semibold text-slate-800">My Profile</h1>
        <form @submit.prevent="save" class="max-w-md space-y-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" v-model="form.name" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Email</label>
                <input type="email" v-model="form.email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Phone</label>
                <input type="text" v-model="form.phone" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Address</label>
                <input type="text" v-model="form.address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">New Password</label>
                <input
                    type="password"
                    autocomplete="new-password"
                    v-model="form.password"
                    placeholder="Leave blank to keep current password"
                    class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                />
            </div>
            <div class="text-right">
                <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-5 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    Save Changes
                </button>
            </div>
        </form>
    </PortalLayout>
</template>
