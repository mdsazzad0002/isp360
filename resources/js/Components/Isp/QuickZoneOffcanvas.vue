<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useApiError } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// Quick zone entry in a side panel (opened from a zone picker, e.g. Quick Area Entry).
// Stacks above modals (z 2100) and other quick panels, below SearchSelect dropdowns (z 2150).
const props = defineProps({ modelValue: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue', 'saved']);
const toast = useToast();
const showError = useApiError();

const form = reactive({ name: '', code: '', description: '' });
const saving = ref(false);
const nameInput = ref(null);

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        Object.assign(form, { name: '', code: '', description: '' });
        setTimeout(() => nameInput.value?.focus(), 50);
    },
);

function close() {
    emit('update:modelValue', false);
}

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/zone', { ...form, is_active: true });
        toast.success(res.data.message);
        emit('saved', res.data.id);
        close();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-[2130] flex justify-end">
            <div class="absolute inset-0 bg-slate-900/40" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in sm:w-[380px]">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-globe-asia-australia"></i> Quick Zone Entry</h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                </div>
                <form class="flex-1 space-y-3 overflow-y-auto p-5 text-sm" @submit.prevent="save">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Zone name</label>
                        <input ref="nameInput" v-model="form.name" required maxlength="100" placeholder="e.g. Mirpur" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                        <input v-model="form.code" maxlength="50" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                        <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="close">Cancel</button>
                        <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Save zone</button>
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
