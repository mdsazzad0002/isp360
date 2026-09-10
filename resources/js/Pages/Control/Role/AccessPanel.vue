<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useToast } from '../../../lib/toast';
import { accessGroups } from '../../../lib/accessGroups';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    role: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue']);
const toast = useToast();
const groups = accessGroups;

const allValues = groups.flatMap((g) => g.items.map((i) => i.value));

const loading = ref(false);
const roleAccess = ref([]);
const onProgress = ref(false);

function isGroupChecked(group) {
    return group.items.every((i) => roleAccess.value.includes(i.value));
}

const isAllChecked = computed(() => allValues.every((v) => roleAccess.value.includes(v)));

function toggleGroup(group, checked) {
    const values = group.items.map((i) => i.value);
    if (checked) {
        roleAccess.value = Array.from(new Set([...roleAccess.value, ...values]));
    } else {
        roleAccess.value = roleAccess.value.filter((v) => !values.includes(v));
    }
}

function toggleAll(checked) {
    roleAccess.value = checked ? [...allValues] : [];
}

function close() {
    emit('update:modelValue', false);
}

async function loadRoleAccess(id) {
    if (!id) return;
    loading.value = true;
    try {
        const res = await axios.post('/get-roleAccess', { id });
        roleAccess.value = Array.isArray(res.data.access) ? res.data.access : [];
    } finally {
        loading.value = false;
    }
}

async function saveData() {
    onProgress.value = true;
    try {
        const res = await axios.post('/save-roleAccess', { id: props.role.id, access: roleAccess.value });
        toast.success(res.data.message);
    } catch (err) {
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    } finally {
        onProgress.value = false;
    }
}

watch(
    () => [props.modelValue, props.role?.id],
    ([open, id]) => {
        if (open && id) loadRoleAccess(id);
    },
    { immediate: true }
);
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[320px] flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-shield-lock"></i> Role Access — {{ role?.name }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto bg-slate-100 p-5">
                    <div v-if="loading" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
                    <template v-else>
                        <div class="mb-3 flex items-center justify-between rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="checkbox" :checked="isAllChecked" @change="toggleAll($event.target.checked)" />
                                Check All
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <div v-for="group in groups" :key="group.key" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <label class="flex cursor-pointer items-center gap-2 bg-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" :checked="isGroupChecked(group)" @change="toggleGroup(group, $event.target.checked)" />
                                    {{ group.label }}
                                </label>
                                <div class="divide-y divide-slate-100">
                                    <label v-for="item in group.items" :key="item.value" class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                                        <input type="checkbox" :value="item.value" v-model="roleAccess" />
                                        {{ item.label }}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3">
                    <button type="button" @click="close" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                    <button type="button" @click="saveData" :disabled="onProgress" class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                        Save Access
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
