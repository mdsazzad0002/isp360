<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Offcanvas from '../../Components/Offcanvas.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import Pagination from '../../Components/Pagination.vue';
import { useToast } from '../../lib/toast';
import { useApiError, fmtDateTime, label } from '../../lib/isp';
import { confirmDialog } from '../../lib/confirm';

defineOptions({ layout: AppLayout });
const props = defineProps({ vendors: Object, cliVendors: [Object, Array], weakRx: Number, statuses: Array, alertKinds: Object, canConfigure: Boolean });
const toast = useToast();
const showError = useApiError();

// ---- OLTs ----
const blankProvision = () => ({ line_profile: '', service_profile: '', vlan: '', gemport: '', onu_type: '' });
const blank = () => ({
    id: null, name: '', vendor: Object.keys(props.vendors)[0], model: '', host: '', snmp_port: 161, snmp_community: '', location: '', notes: '', is_active: true, has_community: false,
    cli_protocol: 'none', cli_port: '', cli_username: '', cli_password: '', cli_enable_password: '', has_cli_password: false, provision: blankProvision(),
});
const PROVISION = { line_profile: 'Line profile', service_profile: 'Service profile', vlan: 'VLAN', gemport: 'GEM port', onu_type: 'ONU type' };
// provisioning values the vendor's authorise command uses
const provisionKeys = (vendor) => (props.cliVendors?.[vendor]?.authorize || []).filter((k) => k in PROVISION);
const form = reactive(blank());
const olts = ref([]);
const busy = ref(null);

const loadOlts = () => axios.post('/isp/get-olts').then((r) => (olts.value = r.data));

async function save() {
    try {
        toast.success((await axios.post('/isp/olt', { ...form })).data.message);
        Object.assign(form, blank());
        loadOlts();
    } catch (err) {
        showError(err);
    }
}
function edit(o) {
    Object.assign(form, blank(), o, { snmp_community: '', cli_password: '', cli_enable_password: '', cli_port: o.cli_port || '', cli_username: o.cli_username || '', provision: { ...blankProvision(), ...(o.provision || {}) } });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
async function run(o, action) {
    busy.value = `${action}${o.id}`;
    try {
        toast.success((await axios.post(`/isp/olt-${action}`, { id: o.id })).data.message);
        if (action === 'poll') loadOnus();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = null;
        loadOlts();
    }
}
async function removeOlt(o) {
    if (!(await confirmDialog({ title: `Delete OLT ${o.name}?`, text: 'Its ONU list is deleted too.' }))) return;
    try {
        toast.success((await axios.post('/isp/delete-olt', { id: o.id })).data.message);
        loadOlts();
        loadOnus();
    } catch (err) {
        showError(err);
    }
}
function showOnus(o, extra = {}) {
    Object.assign(filter, { olt_id: o.id, pon_port: '', status: '', weak: false, linked: '', search: '' }, extra);
    page.value = 1;
    loadOnus();
}

// ---- ONUs ----
const filter = reactive({ olt_id: '', pon_port: '', status: '', weak: false, linked: '', search: '' });
const onus = ref([]);
const ports = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);

function loadOnus() {
    axios.post('/isp/get-onus', { ...filter, page: page.value, per_page: 50 }).then((r) => {
        onus.value = r.data.data;
        ports.value = r.data.ports;
        lastPage.value = r.data.last_page;
        total.value = r.data.total;
    });
}
function applyFilter() {
    page.value = 1;
    loadOnus();
}

// add / edit an ONU by hand, and link it to a connection
const onuForm = reactive({ open: false, id: null, olt_id: '', pon_port: '', onu_id: '', serial: '', mac: '', name: '', notes: '', connection: null });
const connectionOptions = ref([]);
const connLabel = (c) => ({ ...c, display: `${c.code} · ${c.customer?.name || ''}${c.pppoe_username ? ' · ' + c.pppoe_username : ''}` });

function openOnu(o = null) {
    Object.assign(onuForm, { open: true, id: null, olt_id: filter.olt_id || olts.value[0]?.id || '', pon_port: filter.pon_port, onu_id: '', serial: '', mac: '', name: '', notes: '', connection: null });
    if (o) {
        Object.assign(onuForm, { id: o.id, olt_id: o.olt_id, pon_port: o.pon_port, onu_id: o.onu_id ?? '', serial: o.serial || '', mac: o.mac || '', name: o.name || '', notes: o.notes || '' });
        onuForm.connection = o.connection ? connLabel(o.connection) : null;
    }
    connectionOptions.value = onuForm.connection ? [onuForm.connection] : [];
}
async function searchConnections(q, setLoading) {
    setLoading(true);
    try {
        const { data } = await axios.post('/isp/get-connections', { search: q, per_page: 20 });
        connectionOptions.value = data.data.map(connLabel);
    } finally {
        setLoading(false);
    }
}
async function saveOnu() {
    const { open, connection, ...data } = onuForm;
    try {
        toast.success((await axios.post('/isp/onu', { ...data, connection_id: connection?.id || null })).data.message);
        onuForm.open = false;
        loadOnus();
        loadOlts();
    } catch (err) {
        showError(err);
    }
}
async function unlink(o) {
    if (!(await confirmDialog({ title: `Unlink ${o.serial || o.mac || o.pon_port} from ${o.connection.code}?`, confirmButtonText: 'Yes, unlink' }))) return;
    try {
        toast.success((await axios.post('/isp/onu-link', { id: o.id, connection_id: null })).data.message);
        loadOnus();
        loadOlts();
    } catch (err) {
        showError(err);
    }
}
async function removeOnu(o) {
    if (!(await confirmDialog({ title: `Remove ONU ${o.serial || o.mac || o.pon_port + ':' + o.onu_id}?` }))) return;
    try {
        toast.success((await axios.post('/isp/delete-onu', { id: o.id })).data.message);
        loadOnus();
        loadOlts();
    } catch (err) {
        showError(err);
    }
}

// ---- changes on the OLT: preview the commands, then send them ----
const oltById = (id) => olts.value.find((o) => o.id === id);
const cmd = reactive({ open: false, title: '', commands: [], transcript: '', error: '', running: false, send: null });

function preview(title, commands, send) {
    Object.assign(cmd, { open: true, title, commands, transcript: '', error: '', running: false, send });
}
async function sendCommands() {
    cmd.running = true;
    cmd.error = '';
    try {
        const { data } = await cmd.send();
        toast.success(data.message);
        cmd.transcript = data.transcript || '';
        cmd.send = null;
        loadOnus();
        loadOlts();
        loadAlerts();
    } catch (err) {
        cmd.error = err?.response?.data?.message || 'Failed';
    } finally {
        cmd.running = false;
    }
}
async function onuAction(o, action) {
    const payload = { id: o.id, action };
    if (action === 'rename') {
        const name = window.prompt('New name on the OLT (letters, digits, space - _ .)', o.name || '');
        if (!name) return;
        payload.name = name;
    }
    try {
        const { data } = await axios.post('/isp/onu-action', payload);
        const what = { reboot: 'Reboot', rename: 'Rename', delete: 'Delete from the OLT' }[action];
        preview(`${what}: ONU ${o.pon_port}:${o.onu_id ?? ''} on ${o.olt?.name}`, data.commands, () => axios.post('/isp/onu-action', { ...payload, run: true }));
    } catch (err) {
        showError(err);
    }
}

// ONUs waiting for authorisation, and the authorise form
const unreg = reactive({ open: false, olt: null, loading: false, list: [] });
async function loadUnregistered(o) {
    Object.assign(unreg, { open: true, olt: o, loading: true, list: [] });
    try {
        unreg.list = (await axios.post('/isp/get-olt-unregistered', { id: o.id })).data.onus;
    } catch (err) {
        showError(err);
    } finally {
        unreg.loading = false;
    }
}
const auth = reactive({ open: false, olt: null, pon_port: '', serial: '', mac: '', onu_id: '', name: '', connection: null, values: blankProvision() });
function openAuthorize(o, row = {}) {
    Object.assign(auth, { open: true, olt: o, pon_port: row.pon_port || '', serial: row.serial || '', mac: row.mac || '', onu_id: '', name: '', connection: null, values: { ...blankProvision(), ...(o.provision || {}) } });
    connectionOptions.value = [];
}
function authPayload() {
    const values = Object.fromEntries(provisionKeys(auth.olt.vendor).map((k) => [k, auth.values[k]]));
    return { olt_id: auth.olt.id, pon_port: auth.pon_port, serial: auth.serial, mac: auth.mac, onu_id: auth.onu_id, name: auth.name, connection_id: auth.connection?.id || null, ...values };
}
async function previewAuthorize() {
    const payload = authPayload();
    try {
        const { data } = await axios.post('/isp/onu-authorize', payload);
        payload.onu_id = data.onu_id;
        auth.open = false;
        unreg.open = false;
        preview(`Authorise ${payload.serial || payload.mac} as ${payload.pon_port}:${data.onu_id} on ${auth.olt.name}`, data.commands, () => axios.post('/isp/onu-authorize', { ...payload, run: true }));
    } catch (err) {
        showError(err);
    }
}

// ---- alerts ----
const alerts = ref([]);
const alertFilter = reactive({ open: true, kind: '' });
const loadAlerts = () => axios.post('/isp/get-network-alerts', { ...alertFilter, per_page: 30 }).then((r) => (alerts.value = r.data.data));
function duration(a) {
    const mins = Math.round(((a.resolved_at ? new Date(a.resolved_at) : new Date()) - new Date(a.started_at)) / 60000);
    return mins < 60 ? `${mins} min` : mins < 1440 ? `${Math.floor(mins / 60)} h ${mins % 60} min` : `${Math.floor(mins / 1440)} d ${Math.floor((mins % 1440) / 60)} h`;
}
async function notify(a) {
    if (!(await confirmDialog({ title: `Send the outage SMS to ${a.customers} customer(s)?`, text: 'Template: Settings → Customer SMS → Network outage. Sent once per alert.', icon: 'question', confirmButtonText: 'Send' }))) return;
    try {
        toast.success((await axios.post('/isp/network-alert-notify', { id: a.id })).data.message);
        loadAlerts();
    } catch (err) {
        showError(err);
    }
}

onMounted(() => {
    loadOlts();
    loadOnus();
    loadAlerts();
});

const STATUS_CLASS = { online: 'bg-emerald-100 text-emerald-700', offline: 'bg-slate-100 text-slate-600', los: 'bg-red-100 text-red-700', dying_gasp: 'bg-red-100 text-red-700', auth_failed: 'bg-amber-100 text-amber-700', not_found: 'bg-slate-100 text-slate-400', unknown: 'bg-slate-100 text-slate-500' };
// below the alert threshold red, within 2 dB of it amber; stronger than -8 dBm overloads the receiver
const rxClass = (v) => (v === null ? 'text-slate-400' : v < props.weakRx ? 'font-medium text-red-600' : v < props.weakRx + 2 || v > -8 ? 'text-amber-600' : 'text-emerald-700');
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-1 text-base font-semibold text-slate-800">{{ form.id ? `Edit OLT ${form.name}` : 'Add OLT' }}</h1>
            <p class="mb-3 text-xs text-slate-500">
                The server reads the OLT over <b>SNMP v2c</b> (UDP 161) every 5 minutes: ONU status, receive power and distance. Allow this server's IP in the OLT's SNMP settings and give it a read-only community.
                For a brand that is not listed, choose <b>Other</b> and add the ONUs by hand. Below {{ weakRx }} dBm counts as weak signal.
            </p>
            <form class="grid grid-cols-2 gap-2 md:grid-cols-4" @submit.prevent="save">
                <input v-model="form.name" required placeholder="Name (e.g. POP-1 OLT)" :class="input" />
                <select v-model="form.vendor" :class="input"><option v-for="(v, k) in vendors" :key="k" :value="k">{{ v }}</option></select>
                <input v-model="form.model" placeholder="Model (optional)" :class="input" />
                <input v-model="form.host" required placeholder="Management IP / host" :class="input" />
                <input v-model="form.snmp_port" type="number" min="1" max="65535" placeholder="SNMP port (161)" :class="input" />
                <input v-model="form.snmp_community" type="password" autocomplete="new-password" :placeholder="form.id && form.has_community ? 'SNMP community (unchanged)' : 'SNMP read community'" :class="input" />
                <input v-model="form.location" placeholder="Location / POP" :class="input" />
                <template v-if="cliVendors[form.vendor]">
                    <div class="col-span-2 mt-1 text-xs font-semibold text-slate-600 md:col-span-4">
                        CLI login <span class="font-normal text-slate-400">— to authorise, reboot, rename and delete ONUs from here. Every change shows its commands before they are sent.</span>
                    </div>
                    <select v-model="form.cli_protocol" :class="input">
                        <option value="none">No CLI (read only)</option>
                        <option value="ssh">SSH</option>
                        <option value="telnet">Telnet (unencrypted)</option>
                    </select>
                    <template v-if="form.cli_protocol !== 'none'">
                        <input v-model="form.cli_port" type="number" min="1" max="65535" :placeholder="form.cli_protocol === 'ssh' ? 'Port (22)' : 'Port (23)'" :class="input" />
                        <input v-model="form.cli_username" required placeholder="CLI username" autocomplete="off" :class="input" />
                        <input v-model="form.cli_password" type="password" autocomplete="new-password" :placeholder="form.id && form.has_cli_password ? 'CLI password (unchanged)' : 'CLI password'" :class="input" />
                        <input v-model="form.cli_enable_password" type="password" autocomplete="new-password" placeholder="Enable password (if asked)" :class="input" />
                        <input v-for="k in provisionKeys(form.vendor)" :key="k" v-model="form.provision[k]" :placeholder="`Default ${PROVISION[k].toLowerCase()}`" :class="input" />
                    </template>
                </template>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-1 text-sm"><input v-model="form.is_active" type="checkbox" /> Active</label>
                    <button type="submit" class="flex-1 rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">{{ form.id ? 'Update' : 'Save' }}</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="Object.assign(form, blank())">Reset</button>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">OLT</th>
                        <th class="px-3 py-2 font-medium">Host</th>
                        <th class="px-3 py-2 text-end font-medium">ONUs</th>
                        <th class="px-3 py-2 text-end font-medium">Online</th>
                        <th class="px-3 py-2 text-end font-medium">Weak</th>
                        <th class="px-3 py-2 text-end font-medium">Linked</th>
                        <th class="px-3 py-2 font-medium">Last check</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in olts" :key="o.id" class="border-b border-slate-100" :class="{ 'opacity-60': !o.is_active }">
                        <td class="px-3 py-2">
                            <div class="font-medium">
                                {{ o.name }}
                                <button v-if="o.open_alerts" type="button" class="ms-1 rounded bg-red-100 px-1.5 text-xs text-red-700" @click="alertFilter.open = true; loadAlerts()"><i class="bi bi-exclamation-triangle"></i> {{ o.open_alerts }}</button>
                                <span v-if="o.can_configure" class="ms-1 rounded bg-slate-100 px-1.5 text-xs text-slate-500" title="ONU changes over the CLI">{{ o.cli_protocol.toUpperCase() }}</span>
                            </div>
                            <div class="text-xs text-slate-500">{{ vendors[o.vendor] || o.vendor }}<span v-if="o.model"> · {{ o.model }}</span><span v-if="o.location"> · {{ o.location }}</span></div>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ o.host }}:{{ o.snmp_port }}</td>
                        <td class="px-3 py-2 text-end"><button type="button" class="text-brand-600 hover:underline" @click="showOnus(o)">{{ o.onus_total }}</button></td>
                        <td class="px-3 py-2 text-end text-emerald-700">{{ o.onus_online }}</td>
                        <td class="px-3 py-2 text-end"><button v-if="o.onus_weak" type="button" class="font-medium text-red-600 hover:underline" @click="showOnus(o, { weak: true })">{{ o.onus_weak }}</button><span v-else class="text-slate-400">0</span></td>
                        <td class="px-3 py-2 text-end">{{ o.onus_linked }}</td>
                        <td class="max-w-xs px-3 py-2 text-xs">
                            <div :class="String(o.last_status || '').includes('FAILED') ? 'text-red-600' : 'text-slate-600'">{{ o.last_status || '—' }}</div>
                            <div class="text-slate-400">{{ fmtDateTime(o.last_polled_at || o.last_checked_at) }}</div>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2 whitespace-nowrap">
                                <button v-if="o.has_community" type="button" :disabled="!!busy" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="run(o, 'test')"><i class="bi bi-plug"></i> {{ busy === `test${o.id}` ? '…' : 'Test' }}</button>
                                <button v-if="o.can_poll" type="button" :disabled="!!busy" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="run(o, 'poll')"><i class="bi bi-arrow-repeat"></i> {{ busy === `poll${o.id}` ? 'Reading…' : 'Poll now' }}</button>
                                <template v-if="canConfigure && o.can_configure">
                                    <button v-if="cliVendors[o.vendor]?.unregistered" type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="loadUnregistered(o)"><i class="bi bi-plus-circle"></i> New ONUs</button>
                                    <button v-else type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="openAuthorize(o)"><i class="bi bi-plus-circle"></i> Authorise</button>
                                </template>
                                <i class="bi bi-pen cursor-pointer self-center text-brand-500" @click="edit(o)"></i>
                                <i class="bi bi-trash cursor-pointer self-center text-red-500" @click="removeOlt(o)"></i>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!olts.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No OLTs yet</td></tr>
                </tbody>
            </table>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
                <h2 class="me-2 text-sm font-semibold text-slate-700">ONUs <span class="font-normal text-slate-400">({{ total }})</span></h2>
                <select v-model="filter.olt_id" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="filter.pon_port = ''; applyFilter()">
                    <option value="">All OLTs</option>
                    <option v-for="o in olts" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
                <select v-if="filter.olt_id" v-model="filter.pon_port" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="applyFilter">
                    <option value="">All PON ports</option>
                    <option v-for="p in ports" :key="p" :value="p">{{ p }}</option>
                </select>
                <select v-model="filter.status" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="applyFilter">
                    <option value="">Any status</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ label(s) }}</option>
                </select>
                <select v-model="filter.linked" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="applyFilter">
                    <option value="">Linked or not</option>
                    <option value="yes">Linked to a connection</option>
                    <option value="no">Not linked</option>
                </select>
                <label class="flex items-center gap-1 text-sm"><input v-model="filter.weak" type="checkbox" @change="applyFilter" /> Weak signal</label>
                <input v-model="filter.search" placeholder="Serial, MAC, name, customer…" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @keyup.enter="applyFilter" />
                <button type="button" class="ms-auto rounded-md bg-brand-500 px-3 py-1 text-sm text-white" :disabled="!olts.length" @click="openOnu()"><i class="bi bi-plus"></i> Add ONU</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">PON port : ONU</th>
                            <th class="px-3 py-2 font-medium">Serial / MAC</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-end font-medium">RX dBm</th>
                            <th class="px-3 py-2 text-end font-medium">Distance</th>
                            <th class="px-3 py-2 font-medium">Connection</th>
                            <th class="px-3 py-2 font-medium">Last online</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in onus" :key="o.id" class="border-b border-slate-100">
                            <td class="px-3 py-2">
                                <div class="font-mono text-xs">{{ o.pon_port }}<span v-if="o.onu_id !== null">:{{ o.onu_id }}</span></div>
                                <div class="text-xs text-slate-400">{{ o.olt?.name }}<span v-if="o.name"> · {{ o.name }}</span></div>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ o.serial || o.mac || '—' }}</td>
                            <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-xs" :class="STATUS_CLASS[o.status] || STATUS_CLASS.unknown">{{ label(o.status) }}</span></td>
                            <td class="px-3 py-2 text-end font-mono text-xs" :class="rxClass(o.rx_power)">{{ o.rx_power ?? '—' }}</td>
                            <td class="px-3 py-2 text-end text-xs">{{ o.distance_m !== null ? `${o.distance_m} m` : '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                <template v-if="o.connection">
                                    <a :href="`/isp/customer/${o.connection.customer_id}`" class="text-brand-600 hover:underline">{{ o.connection.code }} · {{ o.connection.customer?.name }}</a>
                                    <button type="button" class="ms-1 text-slate-400 hover:text-red-500" title="Unlink" @click="unlink(o)"><i class="bi bi-x-circle"></i></button>
                                </template>
                                <button v-else type="button" class="text-brand-600 hover:underline" @click="openOnu(o)"><i class="bi bi-link-45deg"></i> Link</button>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ fmtDateTime(o.last_seen_at) || '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <template v-if="canConfigure && oltById(o.olt_id)?.can_configure && o.onu_id !== null">
                                        <i class="bi bi-arrow-clockwise cursor-pointer text-amber-600" title="Reboot ONU" @click="onuAction(o, 'reboot')"></i>
                                        <i class="bi bi-input-cursor-text cursor-pointer text-slate-600" title="Rename on the OLT" @click="onuAction(o, 'rename')"></i>
                                        <i v-if="!o.connection" class="bi bi-x-octagon cursor-pointer text-red-600" title="Delete from the OLT" @click="onuAction(o, 'delete')"></i>
                                    </template>
                                    <i class="bi bi-pen cursor-pointer text-brand-500" title="Edit the list entry" @click="openOnu(o)"></i>
                                    <i class="bi bi-trash cursor-pointer text-red-500" @click="removeOnu(o)"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!onus.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">No ONUs. Poll an OLT, or add them by hand.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="p-3"><Pagination v-if="lastPage > 1" :page="page" :total-pages="lastPage" @change="(p) => { page = p; loadOnus(); }" /></div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
                <h2 class="me-2 text-sm font-semibold text-slate-700">Network alerts</h2>
                <label class="flex items-center gap-1 text-sm"><input v-model="alertFilter.open" type="checkbox" @change="loadAlerts" /> Open only</label>
                <select v-model="alertFilter.kind" class="rounded-md border border-slate-300 px-2 py-1 text-sm" @change="loadAlerts">
                    <option value="">All kinds</option>
                    <option v-for="(l, k) in alertKinds" :key="k" :value="k">{{ l }}</option>
                </select>
                <span class="text-xs text-slate-400">OLT unreachable and PON port down also show as a banner for admins and are e-mailed (ISP_ALERT_EMAIL).</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Alert</th>
                            <th class="px-3 py-2 font-medium">Since</th>
                            <th class="px-3 py-2 font-medium">Duration</th>
                            <th class="px-3 py-2 text-end font-medium">Customers</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in alerts" :key="a.id" class="border-b border-slate-100">
                            <td class="px-3 py-2">
                                <span class="me-1 rounded px-1.5 py-0.5 text-xs" :class="a.resolved_at ? 'bg-emerald-100 text-emerald-700' : a.kind === 'onu_weak' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'">{{ a.resolved_at ? 'Recovered' : alertKinds[a.kind] }}</span>
                                {{ a.message }}
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ fmtDateTime(a.started_at) }}</td>
                            <td class="px-3 py-2 text-xs">{{ duration(a) }}</td>
                            <td class="px-3 py-2 text-end">{{ a.customers ?? '—' }}</td>
                            <td class="px-3 py-2 text-end text-xs">
                                <span v-if="a.notified_at" class="text-slate-500">told {{ a.notified_count }} · {{ fmtDateTime(a.notified_at) }}</span>
                                <button v-else-if="a.kind !== 'onu_weak' && !a.resolved_at && a.customers" type="button" class="rounded border border-slate-300 px-2 py-0.5" @click="notify(a)"><i class="bi bi-chat-dots"></i> Notify customers</button>
                            </td>
                        </tr>
                        <tr v-if="!alerts.length"><td colspan="5" class="px-3 py-6 text-center text-slate-400">{{ alertFilter.open ? 'No open alerts' : 'No alerts yet' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- commands preview, then the OLT's answer -->
        <Offcanvas :show="cmd.open" width="sm:w-[640px]" @close="cmd.open = false">
            <div class="flex h-full flex-col">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">{{ cmd.title }}</div>
                <div class="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                    <p class="text-xs text-slate-500">These commands go to the OLT, after the login and its setup commands (configuration mode, no paging). Check them against your OLT's CLI before the first use.</p>
                    <pre class="overflow-x-auto rounded-md bg-slate-900 p-3 font-mono text-xs text-emerald-200">{{ cmd.commands.join('\n') }}</pre>
                    <div v-if="cmd.error" class="rounded-md border border-red-300 bg-red-50 p-2 text-xs text-red-800"><pre class="whitespace-pre-wrap font-mono">{{ cmd.error }}</pre></div>
                    <div v-if="cmd.transcript">
                        <div class="mb-1 text-xs font-semibold text-slate-600">OLT session</div>
                        <pre class="max-h-96 overflow-auto rounded-md bg-slate-100 p-3 font-mono text-xs text-slate-700">{{ cmd.transcript }}</pre>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="cmd.open = false">{{ cmd.send ? 'Cancel' : 'Close' }}</button>
                    <button v-if="cmd.send" type="button" :disabled="cmd.running" class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white" @click="sendCommands">{{ cmd.running ? 'Sending…' : 'Send to the OLT' }}</button>
                </div>
            </div>
        </Offcanvas>

        <!-- ONUs the OLT found but nobody authorised -->
        <Offcanvas :show="unreg.open" width="sm:w-[560px]" @close="unreg.open = false">
            <div class="flex h-full flex-col">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">New ONUs on {{ unreg.olt?.name }}</div>
                <div class="flex-1 overflow-y-auto p-4 text-sm">
                    <p v-if="unreg.loading" class="text-slate-500"><i class="bi bi-hourglass-split"></i> Asking the OLT…</p>
                    <table v-else-if="unreg.list.length" class="w-full text-sm">
                        <tbody>
                            <tr v-for="u in unreg.list" :key="u.serial || u.mac" class="border-b border-slate-100">
                                <td class="py-2 font-mono text-xs">{{ u.pon_port }}</td>
                                <td class="py-2 font-mono text-xs">{{ u.serial || u.mac }}<span v-if="u.known" class="ms-1 font-sans text-amber-600">(on the list)</span></td>
                                <td class="py-2 text-end"><button type="button" class="rounded bg-brand-500 px-2 py-0.5 text-xs text-white" @click="openAuthorize(unreg.olt, u)">Authorise</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-slate-400">The OLT reports no ONUs waiting for authorisation.</p>
                </div>
                <div class="flex justify-between gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="openAuthorize(unreg.olt)">Enter by hand</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="loadUnregistered(unreg.olt)"><i class="bi bi-arrow-repeat"></i> Refresh</button>
                </div>
            </div>
        </Offcanvas>

        <Offcanvas :show="auth.open" width="sm:w-[480px]" @close="auth.open = false">
            <form v-if="auth.olt" class="flex h-full flex-col" @submit.prevent="previewAuthorize">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">Authorise ONU on {{ auth.olt.name }}</div>
                <div class="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">PON port <input v-model="auth.pon_port" required placeholder="0/1/3" :class="input" /></label>
                        <label class="block">ONU id <input v-model="auth.onu_id" type="number" min="0" placeholder="next free" :class="input" /></label>
                        <label v-if="cliVendors[auth.olt.vendor]?.authorize.includes('serial')" class="col-span-2 block">Serial <input v-model="auth.serial" required placeholder="HWTC1A2B3C4D" :class="input" /></label>
                        <label v-if="cliVendors[auth.olt.vendor]?.authorize.includes('mac')" class="col-span-2 block">MAC <input v-model="auth.mac" required placeholder="aa:bb:cc:dd:ee:ff" :class="input" /></label>
                        <label class="col-span-2 block">Name on the OLT <input v-model="auth.name" required maxlength="32" placeholder="customer code or name" :class="input" /></label>
                        <label v-for="k in provisionKeys(auth.olt.vendor)" :key="k" class="block">{{ PROVISION[k] }} <input v-model="auth.values[k]" required :class="input" /></label>
                    </div>
                    <label class="block">Customer connection (optional)
                        <SearchSelect v-model="auth.connection" :options="connectionOptions" label="display" placeholder="Search code, PPPoE user, customer…" @search="searchConnections" />
                    </label>
                    <p class="text-xs text-slate-500">The next step shows the exact commands; nothing is sent before you confirm. Defaults for the profiles and VLAN come from the OLT's settings.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="auth.open = false">Cancel</button>
                    <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Preview commands</button>
                </div>
            </form>
        </Offcanvas>

        <Offcanvas :show="onuForm.open" width="sm:w-[480px]" @close="onuForm.open = false">
            <form class="flex h-full flex-col" @submit.prevent="saveOnu">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">{{ onuForm.id ? 'Edit ONU' : 'Add ONU' }}</div>
                <div class="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                    <label class="block">OLT
                        <select v-model="onuForm.olt_id" required :class="input"><option v-for="o in olts" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">PON port <input v-model="onuForm.pon_port" required placeholder="GPON 0/1/3" :class="input" /></label>
                        <label class="block">ONU id <input v-model="onuForm.onu_id" type="number" min="0" placeholder="5" :class="input" /></label>
                        <label class="block">Serial (GPON) <input v-model="onuForm.serial" placeholder="HWTC1A2B3C4D" :class="input" /></label>
                        <label class="block">MAC (EPON) <input v-model="onuForm.mac" placeholder="aa:bb:cc:dd:ee:ff" :class="input" /></label>
                    </div>
                    <label class="block">Name / description <input v-model="onuForm.name" :class="input" /></label>
                    <label class="block">Customer connection
                        <SearchSelect v-model="onuForm.connection" :options="connectionOptions" label="display" placeholder="Search code, PPPoE user, customer…" @search="searchConnections" />
                    </label>
                    <label class="block">Notes <input v-model="onuForm.notes" :class="input" /></label>
                    <p class="text-xs text-slate-500">On an OLT that is polled, status and power come from the OLT; the link to the connection stays when the ONU moves port (matched by serial or MAC).</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="onuForm.open = false">Cancel</button>
                    <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Save</button>
                </div>
            </form>
        </Offcanvas>
    </div>
</template>
