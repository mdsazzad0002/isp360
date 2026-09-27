<script setup>
import { ref, reactive, watch } from 'vue';
import axios from 'axios';
import SearchSelect from '../SearchSelect.vue';
import QuickAreaOffcanvas from './QuickAreaOffcanvas.vue';
import { useApiError } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// Quick box entry in a side panel, opened from a box picker (e.g. the connection form).
// Sits above modals (z 2100) but below SearchSelect dropdowns (z 2150).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    areaId: { type: [Number, null], default: null }, // preselect, e.g. the customer's area
});
const emit = defineEmits(['update:modelValue', 'saved']);
const toast = useToast();
const showError = useApiError();

const areas = ref([]);
const area = ref(null);
const saving = ref(false);
const form = reactive({});
const showQuickArea = ref(false);

async function loadAreas() {
    const res = await axios.post('/get-area');
    areas.value = res.data.map((x) => ({ ...x, display_name: x.zone?.name ? `${x.name} — ${x.zone.name}` : x.name }));
}
async function onAreaCreated(id) {
    await loadAreas();
    area.value = areas.value.find((a) => a.id === id) || null;
}

function reset() {
    Object.assign(form, { name: '', code: '', capacity: 16, location: '', latitude: '', longitude: '', description: '' });
}

watch(
    () => props.modelValue,
    async (open) => {
        if (!open) return;
        reset();
        await loadAreas();
        area.value = areas.value.find((a) => a.id === props.areaId) || null;
    },
);

function close() {
    emit('update:modelValue', false);
}

function useMyLocation() {
    if (!navigator.geolocation) return toast.error('Location is not available in this browser');
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.latitude = pos.coords.latitude.toFixed(7);
            form.longitude = pos.coords.longitude.toFixed(7);
        },
        () => toast.error('Could not read your location'),
    );
}

async function save() {
    if (!area.value) return toast.error('Select an area');
    saving.value = true;
    try {
        const res = await axios.post('/isp/box', { ...form, area_id: area.value.id, is_active: true });
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
        <div v-if="modelValue" class="fixed inset-0 z-[2120] flex justify-end">
            <div class="absolute inset-0 bg-slate-900/40" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in sm:w-[440px]">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-hdd-network"></i> Quick Box Entry</h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                </div>
                <form class="flex-1 space-y-3 overflow-y-auto p-5 text-sm" @submit.prevent="save">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                        <div class="flex gap-1">
                            <div class="min-w-0 flex-1"><SearchSelect :options="areas" v-model="area" label="display_name" :placeholder="areas.length ? 'Select area' : 'No area yet — add one'" /></div>
                            <button type="button" class="shrink-0 rounded-md border border-slate-300 px-2.5 text-brand-600 hover:bg-brand-50" title="Quick add area" @click="showQuickArea = true"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Box name</label>
                        <input v-model="form.name" required maxlength="100" placeholder="e.g. Box-MIR-001" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                            <input v-model="form.code" maxlength="50" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Capacity (ports)</label>
                            <input v-model="form.capacity" type="number" min="0" max="1024" title="0 = unlimited" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Location</label>
                        <input v-model="form.location" placeholder="Pole / building / landmark" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div>
                        <label class="mb-1 flex items-center justify-between text-xs font-medium text-slate-600">
                            GPS (optional)
                            <button type="button" class="text-brand-600 hover:underline" @click="useMyLocation"><i class="bi bi-crosshair"></i> Use my location</button>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model="form.latitude" placeholder="Latitude" class="rounded-md border border-slate-300 px-3 py-1.5" />
                            <input v-model="form.longitude" placeholder="Longitude" class="rounded-md border border-slate-300 px-3 py-1.5" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                        <input v-model="form.description" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="close">Cancel</button>
                        <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Save box</button>
                    </div>
                </form>
            </div>
        </div>
        <QuickAreaOffcanvas v-model="showQuickArea" @saved="onAreaCreated" />
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
