<script setup>
import { ref, reactive, watch, computed } from 'vue';
import axios from 'axios';
import Offcanvas from '../Offcanvas.vue';
import SearchSelect from '../SearchSelect.vue';
import StatusBadge from './StatusBadge.vue';
import SyncBadge from './SyncBadge.vue';
import TerminalOffcanvas from './TerminalOffcanvas.vue';
import { money, fmtDate, label, today, promptReason, useApiError } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// Connection details, full history and lifecycle actions.
const props = defineProps({
    show: Boolean,
    connectionId: { type: [Number, null], default: null },
    can: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'changed', 'edit']);
const toast = useToast();
async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`Copied ${text}`);
    } catch {
        toast.error('Copy is blocked by the browser');
    }
}
const showError = useApiError();

const conn = ref(null);
const busy = ref(false);
const secret = ref('');
const online = ref(null);
const pkg = reactive({ open: false, options: [], selected: null, effective_date: today(), reason: '' });

const actions = computed(() => {
    const s = conn.value?.status;
    return {
        activate: ['pending', 'inactive'].includes(s),
        suspend: s === 'active',
        reactivate: s === 'suspended',
        deactivate: ['active', 'suspended'].includes(s),
        terminate: s && s !== 'terminated',
    };
});

function load() {
    if (!props.connectionId) return;
    conn.value = null;
    secret.value = '';
    pkg.open = false;
    online.value = null;
    axios.post('/isp/get-connection', { id: props.connectionId }).then((res) => {
        conn.value = res.data;
        if (['pppoe', 'hotspot'].includes(res.data.connection_type)) axios.post('/isp/connection-online', { id: res.data.id }).then((r) => (online.value = r.data));
    });
}

async function syncNow() {
    busy.value = true;
    try {
        const res = await axios.post('/isp/connection-sync', { id: conn.value.id });
        toast.success(res.data.message);
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
        load();
    }
}
watch(() => [props.show, props.connectionId], ([s]) => s && load(), { immediate: true });

const showTerminal = ref(false);
async function verifyNow() {
    busy.value = true;
    try {
        const res = await axios.post('/isp/connection-verify', { id: conn.value.id });
        const result = res.data.results?.[conn.value.id];
        result?.status === 'mismatch' || result?.status === 'failed' ? toast.error(`${res.data.message}: ${result.issues.join(' ')}`) : toast.success(res.data.message);
        emit('changed');
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
        load();
    }
}

async function act(action) {
    let reason = null;
    if (action !== 'activate') {
        reason = await promptReason(`${label(action)} ${conn.value.code}?`, {
            text: action === 'terminate' ? 'Termination is final: the connection can no longer be billed, edited or reactivated.' : '',
            confirmButtonText: label(action),
        });
        if (!reason) return;
    }
    busy.value = true;
    try {
        const res = await axios.post('/isp/connection-action', { id: conn.value.id, action, reason, date: today() });
        toast.success(res.data.message);
        emit('changed');
        load();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}

async function reveal() {
    try {
        const res = await axios.post('/isp/get-connection-secret', { id: conn.value.id });
        secret.value = res.data.password || '(not set)';
    } catch (err) {
        showError(err);
    }
}

async function openPackage() {
    const res = await axios.post('/isp/get-packages', { activeOnly: true });
    Object.assign(pkg, { open: true, options: res.data.filter((p) => p.id !== conn.value.package_id && (!p.reseller_id || p.reseller_id === conn.value.customer?.reseller_id)), selected: null, reason: '', effective_date: today() });
}

async function savePackage() {
    if (!pkg.selected) return toast.error('Select the new package');
    busy.value = true;
    try {
        const res = await axios.post('/isp/connection-change-package', { id: conn.value.id, package_id: pkg.selected.id, effective_date: pkg.effective_date, reason: pkg.reason });
        toast.success(res.data.message);
        emit('changed');
        load();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}

function describe(h) {
    const parts = [];
    const n = h.new_values || {};
    const o = h.old_values || {};
    Object.keys(n).forEach((k) => parts.push(o[k] !== undefined ? `${label(k)}: ${o[k] ?? '—'} → ${n[k] ?? '—'}` : `${label(k)}: ${n[k]}`));
    return parts.join(' · ');
}
</script>

<template>
    <Offcanvas :show="show" width="sm:w-[760px]" @close="emit('close')">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-base font-semibold text-slate-800">Connection {{ conn?.code }} <StatusBadge v-if="conn" :status="conn.status" class="ml-2" /> <SyncBadge v-if="conn" :connection="conn" class="ml-1 align-middle" /></h2>
            <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div v-if="!conn" class="p-8 text-center text-sm text-slate-400">Loading...</div>
        <div v-else class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 text-sm">
            <div class="grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-3">
                <div><span class="text-slate-400">Customer</span><br />{{ conn.customer?.name }} ({{ conn.customer?.code }})</div>
                <div><span class="text-slate-400">Package</span><br />{{ conn.package?.name }} · Tk {{ money(conn.package?.price) }}<span v-if="Number(conn.discount)"> − {{ money(conn.discount) }}</span></div>
                <div><span class="text-slate-400">Type</span><br />{{ label(conn.connection_type) }}</div>
                <div><span class="text-slate-400">{{ conn.connection_type === 'hotspot' ? 'Hotspot' : 'PPPoE' }} user</span><br /><span class="font-mono">{{ conn.pppoe_username || '—' }}</span>
                    <button v-if="conn.pppoe_username" type="button" class="ml-1 text-slate-400 hover:text-brand-600" title="Copy" @click="copyText(conn.pppoe_username)"><i class="bi bi-copy text-xs"></i></button>
                    <button v-if="can.connection" type="button" class="ml-2 text-xs text-brand-600 hover:underline" title="Test this connection in the terminal" @click="showTerminal = true"><i class="bi bi-terminal"></i> Terminal</button>
                </div>
                <div>
                    <span class="text-slate-400">{{ conn.connection_type === 'hotspot' ? 'Hotspot' : 'PPPoE' }} password</span><br />
                    <span v-if="secret" class="font-mono">{{ secret }}</span>
                    <button v-else-if="can.connectionSecret" type="button" class="text-xs text-brand-600 hover:underline" @click="reveal"><i class="bi bi-eye"></i> Show (audited)</button>
                    <span v-else>••••••</span>
                </div>
                <div><span class="text-slate-400">Static IP / MAC</span><br />{{ conn.static_ip || '—' }} / {{ conn.mac_address || '—' }}</div>
                <div><span class="text-slate-400">Box</span><br />{{ conn.box ? `${conn.box.name}${conn.box.code ? ' (' + conn.box.code + ')' : ''}` : '—' }}</div>
                <div><span class="text-slate-400">Installed / Activated</span><br />{{ fmtDate(conn.installation_date) || '—' }} / {{ fmtDate(conn.activation_date) || '—' }}</div>
                <div><span class="text-slate-400">Next billing from</span><br />{{ fmtDate(conn.next_billing_date) || '—' }}</div>
            </div>
            <div v-if="online && online.managed" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-2">
                <span>
                    <i class="bi bi-router"></i> {{ online.router }}:
                    <span v-if="online.error" class="text-red-600">{{ online.error }}</span>
                    <span v-else-if="online.online" class="font-medium text-emerald-700"><i class="bi bi-circle-fill text-[8px]"></i> Online · {{ online.session?.address }} · up {{ online.session?.uptime }}</span>
                    <span v-else class="text-slate-500"><i class="bi bi-circle text-[8px]"></i> Offline</span>
                </span>
            </div>
            <div class="flex flex-wrap items-start justify-between gap-2 rounded-md border p-2" :class="conn.status === 'active' && !['synced', 'not_managed'].includes(conn.network_sync_status) ? 'border-red-200 bg-red-50/50' : 'border-slate-200'">
                <div>
                    <div class="mb-0.5 text-xs text-slate-400">Router sync</div>
                    <SyncBadge :connection="conn" show-note />
                    <div class="mt-0.5 text-[11px] text-slate-400">
                        pushed {{ conn.network_synced_at ? fmtDate(conn.network_synced_at) + ' ' + String(conn.network_synced_at).slice(11, 16) : 'never' }}
                        · verified {{ conn.network_checked_at ? fmtDate(conn.network_checked_at) + ' ' + String(conn.network_checked_at).slice(11, 16) : 'never' }}
                    </div>
                </div>
                <div class="flex gap-2 text-xs">
                    <button type="button" :disabled="busy" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50" title="Compare the router with billing (read-only)" @click="verifyNow"><i class="bi bi-search"></i> Verify</button>
                    <button v-if="can.connectionAction" type="button" :disabled="busy" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-50" @click="syncNow"><i class="bi bi-arrow-repeat"></i> Sync now</button>
                </div>
            </div>
            <div v-if="conn.status === 'suspended'" class="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-800">
                Suspended {{ fmtDate(conn.suspended_at) }} — {{ conn.suspension_reason }} ({{ conn.suspended_by ? 'by user' : 'by system' }})
            </div>

            <div v-if="pkg.open" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <SearchSelect :options="pkg.options" v-model="pkg.selected" label="display_name" placeholder="New package" />
                    <input v-model="pkg.effective_date" type="date" class="rounded-md border border-slate-300 px-2 py-1.5" />
                    <input v-model="pkg.reason" type="text" placeholder="Reason" class="rounded-md border border-slate-300 px-2 py-1.5" />
                </div>
                <p class="mt-1 text-xs text-slate-500">The new price applies from the next invoice. Already issued invoices are not changed.</p>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5" @click="pkg.open = false">Cancel</button>
                    <button type="button" :disabled="busy" class="rounded-md bg-brand-500 px-3 py-1.5 text-white disabled:opacity-50" @click="savePackage">Change package</button>
                </div>
            </div>

            <div>
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Package history</h3>
                <div v-for="p in conn.package_histories" :key="p.id" class="flex justify-between border-b border-slate-100 py-1">
                    <span>{{ fmtDate(p.effective_date) }} · {{ p.old_package?.name ? p.old_package.name + ' → ' : '' }}{{ p.new_package?.name }} <span class="text-slate-400">{{ p.reason }}</span></span>
                    <span>{{ p.old_price !== null ? money(p.old_price) + ' → ' : '' }}{{ money(p.new_price) }}</span>
                </div>
            </div>
            <div>
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Connection history</h3>
                <div v-for="h in conn.histories" :key="h.id" class="border-b border-slate-100 py-1.5">
                    <div class="flex justify-between">
                        <span class="font-medium text-slate-700">{{ label(h.action) }}</span>
                        <span class="text-xs text-slate-400">{{ fmtDate(h.created_at) }} · {{ h.created_by?.name || 'System' }}</span>
                    </div>
                    <div class="text-xs text-slate-500">{{ describe(h) }}<span v-if="h.reason"> — {{ h.reason }}</span></div>
                </div>
            </div>
        </div>
        <div v-if="conn" class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3">
            <template v-if="can.connectionAction">
                <button v-if="actions.activate" :disabled="busy" type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white" @click="act('activate')">Activate</button>
                <button v-if="actions.reactivate" :disabled="busy" type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white" @click="act('reactivate')">Reactivate</button>
                <button v-if="actions.suspend" :disabled="busy" type="button" class="rounded-md border border-amber-300 px-3 py-1.5 text-sm text-amber-700 hover:bg-amber-50" @click="act('suspend')">Suspend</button>
                <button v-if="actions.deactivate" :disabled="busy" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="act('deactivate')">Deactivate</button>
                <button v-if="actions.terminate" :disabled="busy" type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50" @click="act('terminate')">Terminate</button>
                <button v-if="conn.status !== 'terminated' && !pkg.open" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="openPackage">Change package</button>
            </template>
            <button v-if="can.connection && conn.status !== 'terminated'" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="emit('edit', conn)"><i class="bi bi-pen"></i> Edit</button>
        </div>
        <TerminalOffcanvas v-if="conn" v-model="showTerminal" :connections="[conn]" :connection-id="conn.id" @changed="load(); emit('changed')" />
    </Offcanvas>
</template>
