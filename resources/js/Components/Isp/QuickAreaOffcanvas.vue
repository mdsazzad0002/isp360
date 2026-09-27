<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import SearchSelect from '../SearchSelect.vue';
import QuickZoneOffcanvas from './QuickZoneOffcanvas.vue';
import { useApiError } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// Quick area entry in a side panel: pick a zone (or add one with "+"), name the area.
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    zoneId: { type: [Number, null], default: null }, // preselect
});
const emit = defineEmits(['update:modelValue', 'saved']);
const toast = useToast();
const showError = useApiError();

const zones = ref([]);
const zone = ref(null);
const form = reactive({ name: '', code: '' });
const saving = ref(false);
const showQuickZone = ref(false);

async function loadZones() {
    const res = await axios.post('/isp/get-zones');
    zones.value = res.data;
}

watch(
    () => props.modelValue,
    async (open) => {
        if (!open) return;
        Object.assign(form, { name: '', code: '' });
        await loadZones();
        zone.value = zones.value.find((z) => z.id === props.zoneId) || (zones.value.length === 1 ? zones.value[0] : null);
    },
);

async function onZoneCreated(id) {
    await loadZones();
    zone.value = zones.value.find((z) => z.id === id) || null;
}

function close() {
    emit('update:modelValue', false);
}

async function save() {
    if (!zone.value) return toast.error('Select a zone');
    saving.value = true;
    try {
        const res = await axios.post('/area', { name: form.name, code: form.code || null, zone_id: zone.value.id });
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
        <div v-if="modelValue" class="fixed inset-0 z-[2125] flex justify-end">
            <div class="absolute inset-0 bg-slate-900/40" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in sm:w-[400px]">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-geo-alt"></i> Quick Area Entry</h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                </div>
                <form class="flex-1 space-y-3 overflow-y-auto p-5 text-sm" @submit.prevent="save">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Zone</label>
                        <div class="flex gap-1">
                            <div class="min-w-0 flex-1"><SearchSelect :options="zones" v-model="zone" label="name" :placeholder="zones.length ? 'Select zone' : 'No zone yet — add one'" /></div>
                            <button type="button" class="shrink-0 rounded-md border border-slate-300 px-2.5 text-brand-600 hover:bg-brand-50" title="Quick add zone" @click="showQuickZone = true"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area name</label>
                        <input v-model="form.name" required maxlength="100" placeholder="e.g. Mirpur-10" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                        <input v-model="form.code" maxlength="50" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="close">Cancel</button>
                        <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white disabled:opacity-50">Save area</button>
                    </div>
                </form>
            </div>
        </div>
        <QuickZoneOffcanvas v-model="showQuickZone" @saved="onZoneCreated" />
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
