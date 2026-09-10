<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import { useToast } from '../lib/toast';

const emit = defineEmits(['created']);

const toast = useToast();

function emptyForm() {
    return { name: '' };
}

const show = ref(false);
const onProgress = ref(false);
const form = reactive(emptyForm());
const nameInput = ref(null);

function open() {
    Object.assign(form, emptyForm());
    show.value = true;
    setTimeout(() => nameInput.value?.focus(), 50);
}

function close() {
    if (onProgress.value) return;
    show.value = false;
}

async function saveData() {
    if (!form.name.trim()) {
        toast.error('Department name is required');
        return;
    }
    onProgress.value = true;
    try {
        const res = await axios.post('/department', { name: form.name });
        toast.success(res.data.message);

        const listRes = await axios.post('/get-department');
        const created = listRes.data.find((d) => d.name === form.name) || null;

        emit('created', { list: listRes.data, department: created });

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
        title="Add new department"
        class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-md bg-brand-500 text-white hover:bg-brand-600"
    >
        <i class="bi bi-plus-lg"></i>
    </button>

    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-full min-w-[50%] flex-col bg-white shadow-2xl animate-slide-in md:w-1/2">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-800">Quick Add Department</h2>
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
