<script setup>
import { reactive, computed } from 'vue';
import axios from 'axios';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';
import { confirmDialog } from '../../lib/confirm';

// One action for the ticked connections, or for every connection matching the list's filters.
// The server counts them first; nothing changes before that number is confirmed.
const props = defineProps({
    ids: { type: Array, default: () => [] },
    allMatching: Boolean,
    filters: { type: Object, default: () => ({}) }, // the list's filters, for "all matching"
    total: { type: Number, default: 0 },
    pageCount: { type: Number, default: 0 },
    packages: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    types: { type: Object, default: () => ({ installation: 'New installation', shifting: 'Shifting', repair: 'Repair', disconnection: 'Disconnection', pickup: 'Equipment pickup', other: 'Other' }) },
});
const emit = defineEmits(['done', 'clear', 'all']);
const toast = useToast();
const showError = useApiError();

const ACTIONS = computed(() => ({
    ...(props.can.connectionAction ? { suspend: 'Suspend', reactivate: 'Reactivate', deactivate: 'Deactivate (hold)', change_package: 'Change package', sync: 'Push to router again' } : {}),
    ...(props.can.bulkSms ? { sms: 'Send SMS' } : {}),
    ...(props.can.workOrder ? { work_order: 'Create work orders' } : {}),
}));
const f = reactive({ action: '', reason: '', package_id: '', message: '', sms_kind: 'service', wo_type: 'repair', notes: '', busy: false, failed: [] });
const count = computed(() => (props.allMatching ? props.total : props.ids.length));

function payload(run) {
    const scope = props.allMatching ? { all_matching: true, ...props.filters } : { ids: props.ids };
    const { busy, failed, ...fields } = f;
    return { ...scope, ...fields, run };
}
async function go() {
    f.failed = [];
    f.busy = true;
    try {
        const { data } = await axios.post('/isp/connection-bulk', payload(false));
        const ok = await confirmDialog({
            title: `${ACTIONS.value[f.action]}: ${data.count} connection(s)?`,
            text: data.sample.join(', ') + (data.count > data.sample.length ? ', …' : ''),
            icon: 'question',
            confirmButtonText: 'Yes, do it',
        });
        if (!ok) return;
        const res = await axios.post('/isp/connection-bulk', payload(true));
        (res.data.failed?.length ? toast.error : toast.success)(res.data.message);
        f.failed = res.data.failed || [];
        emit('done');
        if (!f.failed.length) emit('clear');
    } catch (err) {
        showError(err);
    } finally {
        f.busy = false;
    }
}
const input = 'rounded-md border border-slate-300 px-2 py-1 text-sm';
</script>

<template>
    <div class="mb-3 rounded-md border border-brand-200 bg-brand-50/40 p-2 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <b>{{ count }} selected</b>
            <button v-if="!allMatching && ids.length === pageCount && total > pageCount" type="button" class="text-brand-600 hover:underline" @click="emit('all')">Select all {{ total }} matching the filter</button>
            <button type="button" class="text-slate-500 hover:underline" @click="emit('clear')">Clear</button>
            <select v-model="f.action" :class="input">
                <option value="">— action —</option>
                <option v-for="(l, k) in ACTIONS" :key="k" :value="k">{{ l }}</option>
            </select>
            <input v-if="['suspend', 'reactivate', 'deactivate', 'change_package'].includes(f.action)" v-model="f.reason" :required="f.action !== 'change_package'" placeholder="Reason" class="w-56" :class="input" />
            <select v-if="f.action === 'change_package'" v-model="f.package_id" :class="input">
                <option value="">— new package —</option>
                <option v-for="p in packages" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <template v-if="f.action === 'work_order'">
                <select v-model="f.wo_type" :class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                <input v-model="f.notes" placeholder="What to do" class="w-64" :class="input" />
            </template>
            <button v-if="f.action" type="button" :disabled="f.busy || (f.action === 'sms' && !f.message) || (f.action === 'change_package' && !f.package_id)" class="rounded-md bg-brand-500 px-3 py-1 text-white disabled:opacity-50" @click="go">
                {{ f.busy ? 'Working…' : 'Apply' }}
            </button>
        </div>
        <div v-if="f.action === 'sms'" class="mt-2 grid gap-2 md:grid-cols-4">
            <textarea v-model="f.message" rows="2" maxlength="640" class="md:col-span-3" :class="input" placeholder="Dear {name}, … Placeholders: {name} {code} {connection} {package} {balance}"></textarea>
            <div class="space-y-1 text-xs">
                <label class="flex items-center gap-1"><input v-model="f.sms_kind" type="radio" value="service" /> Service notice (to everyone)</label>
                <label class="flex items-center gap-1"><input v-model="f.sms_kind" type="radio" value="promotion" /> Promotion (skips customers who opted out)</label>
                <div class="text-slate-500">{{ f.message.length }} characters</div>
            </div>
        </div>
        <div v-if="f.failed.length" class="mt-2 rounded border border-red-200 bg-red-50 p-2 text-xs text-red-800">
            <div v-for="x in f.failed" :key="x.code"><b>{{ x.code }}</b>: {{ x.error }}</div>
        </div>
    </div>
</template>
