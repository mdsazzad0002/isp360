<script setup>
import { reactive, computed, watch, ref } from 'vue';
import axios from 'axios';
import { useToast } from '../../lib/toast';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    mode: { type: String, required: true }, // 'customer' | 'supplier'
});

const emit = defineEmits(['update:modelValue', 'created']);
const toast = useToast();

const label = computed(() => (props.mode === 'customer' ? 'Customer' : 'Supplier'));
const storeUrl = computed(() => `/${props.mode}`);

function emptyForm() {
    return { name: '', phone: '', address: '' };
}

const form = reactive(emptyForm());
const saving = ref(false);

watch(
    () => props.modelValue,
    (open) => {
        if (open) Object.assign(form, emptyForm());
    }
);

function close() {
    emit('update:modelValue', false);
}

async function save() {
    if (!form.name || !form.phone) {
        toast.error('Name and phone are required');
        return;
    }
    saving.value = true;
    try {
        await axios.post(storeUrl.value, form);
        toast.success(`${label.value} added successfully`);
        emit('created', { name: form.name, phone: form.phone });
        close();
    } catch (err) {
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[300px] max-w-sm flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-person-plus"></i> Quick Add {{ label }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <form @submit.prevent="save" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                            <input type="text" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Phone</label>
                            <input type="text" v-model="form.phone" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Address</label>
                            <input type="text" v-model="form.address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </form>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3">
                    <button type="button" @click="close" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="button" :disabled="saving" @click="save" class="inline-flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                        <i class="bi bi-check-lg"></i> Save
                    </button>
                </div>
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
