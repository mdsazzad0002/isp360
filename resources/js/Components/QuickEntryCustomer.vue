<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import SearchSelect from './SearchSelect.vue';
import { useToast } from '../lib/toast';

const props = defineProps({
    areas: { type: Array, default: () => [] },
});

const emit = defineEmits(['created', 'open']);

const toast = useToast();

function emptyForm() {
    return {
        name: '',
        phone: '',
        owner: '',
        email: '',
        type: 'retail',
        address: '',
    };
}

const show = ref(false);
const onProgress = ref(false);
const form = reactive(emptyForm());
const selectedArea = ref(null);
const nameInput = ref(null);

function open() {
    emit('open');
    Object.assign(form, emptyForm());
    selectedArea.value = null;
    show.value = true;
    setTimeout(() => nameInput.value?.focus(), 50);
}

function close() {
    if (onProgress.value) return;
    show.value = false;
}

async function saveData() {
    if (!form.name.trim() || !form.phone.trim()) {
        toast.error('Name and Phone are required');
        return;
    }
    onProgress.value = true;
    try {
        const res = await axios.post('/customer', { ...form, area_id: selectedArea.value ? selectedArea.value.id : '' });
        toast.success(res.data.message);

        const customerRes = await axios.post('/get-customer', { search: form.phone });
        const created = customerRes.data.find((c) => c.phone === form.phone) || null;

        if (created) {
            emit('created', { ...created, display_name: `${created.name} - ${created.phone} - ${created.address ?? ''}`, type: created.type || 'general' });
        }

        onProgress.value = false;
        show.value = false;
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

defineExpose({ open });
</script>

<template>
    <button
        type="button"
        @click="open"
        title="Add new customer"
        class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-md bg-brand-500 text-white hover:bg-brand-600"
    >
        <i class="bi bi-plus-lg"></i>
    </button>

    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-full min-w-[50%] flex-col bg-white shadow-2xl animate-slide-in md:w-1/2">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-800">Quick Add Customer</h2>
                    <button type="button" @click="close" :disabled="onProgress" class="text-slate-400 hover:text-slate-600 disabled:opacity-40">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form @submit.prevent="saveData" class="flex flex-1 flex-col overflow-y-auto">
                    <div class="flex-1 space-y-3 px-5 py-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Name *</label>
                            <input ref="nameInput" type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Phone *</label>
                            <input type="text" autocomplete="off" v-model="form.phone" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Owner</label>
                            <input type="text" autocomplete="off" v-model="form.owner" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Address</label>
                            <input type="text" autocomplete="off" v-model="form.address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div v-if="areas.length">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                            <SearchSelect :options="areas" v-model="selectedArea" label="name" placeholder="Select area" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Email</label>
                            <input type="email" autocomplete="off" v-model="form.email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Customer Type</label>
                            <div class="flex gap-4 text-sm">
                                <label class="flex items-center gap-1.5"><input type="radio" value="retail" v-model="form.type" /> Retail</label>
                                <label class="flex items-center gap-1.5"><input type="radio" value="wholesale" v-model="form.type" /> Wholesale</label>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                        <button type="button" @click="close" :disabled="onProgress" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50">
                            Cancel
                        </button>
                        <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                            {{ onProgress ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
.animate-slide-in {
    animation: slide-in 0.25s ease-out;
}
</style>
