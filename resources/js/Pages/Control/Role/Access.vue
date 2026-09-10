<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { useCan } from '../../../lib/can';
import { accessGroups } from '../../../lib/accessGroups';

defineOptions({ layout: AppLayout });

const props = defineProps({
    role: { type: Object, required: true },
});

const toast = useToast();
const can = useCan();
const groups = accessGroups;

const allValues = groups.flatMap((g) => g.items.map((i) => i.value));

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

function loadRoleAccess() {
    axios.post('/get-roleAccess', { id: props.role.id }).then((res) => {
        roleAccess.value = Array.isArray(res.data.access) ? res.data.access : [];
    });
}

async function saveData() {
    onProgress.value = true;
    try {
        const res = await axios.post('/save-roleAccess', { id: props.role.id, access: roleAccess.value });
        toast.success(res.data.message);
        loadRoleAccess();
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

onMounted(loadRoleAccess);
</script>

<template>
    <div class="mx-auto  p-4">
        <div class="mb-3 rounded-lg border border-slate-200 bg-white p-4 text-center shadow-sm">
            <h1 class="text-lg font-semibold text-slate-800">Role Access For {{ role.name }}</h1>
        </div>

        <div class="mb-3 flex items-center justify-between rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" :checked="isAllChecked" @change="toggleAll($event.target.checked)" />
                Check All
            </label>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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

        <div v-if="can('entry') || can('update')" class="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-right shadow-sm">
            <button type="button" @click="saveData" :disabled="onProgress" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                Save Access
            </button>
        </div>
    </div>
</template>
