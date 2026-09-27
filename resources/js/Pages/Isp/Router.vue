<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusBadge from '../../Components/Isp/StatusBadge.vue';
import RouterSetupGuide from '../../Components/Isp/RouterSetupGuide.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { fmtDate, useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

function blank() {
    return { id: null, name: '', host: '', port: '', use_https: false, username: 'admin', password: '', is_default: true, is_active: true };
}
const form = reactive(blank());
const routers = ref([]);
const busy = ref(null);
const sessions = reactive({ router: null, rows: [], loading: false });

function load() {
    axios.post('/isp/get-routers').then((r) => (routers.value = r.data));
}
// Setup guide: the picked router, else the default one, else the first
const guideId = ref(null);
const guideRouter = computed(() => routers.value.find((r) => r.id === guideId.value) || null); // only after Setup is clicked
const guideEl = ref(null);
async function openGuide(r) {
    if (guideId.value === r.id) {
        guideId.value = null; // Setup again closes it
        return;
    }
    guideId.value = r.id;
    await nextTick();
    guideEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function call(url, data, key) {
    busy.value = key;
    try {
        const res = await axios.post(url, data);
        toast.success(res.data.message);
        load();
        return res.data;
    } catch (err) {
        showError(err);
        load();
        return null;
    } finally {
        busy.value = null;
    }
}

async function save() {
    const ok = await call('/isp/router', { ...form }, 'save');
    if (ok) Object.assign(form, blank());
}
function edit(r) {
    Object.assign(form, { ...blank(), ...r, port: r.port || '', password: '' });
}
async function remove(r) {
    if (await confirmDialog({ title: `Delete router ${r.name}?` })) call('/isp/delete-router', { id: r.id }, 'del' + r.id);
}
async function syncAll(r) {
    if (await confirmDialog({ title: `Push all PPPoE users to ${r.name}?`, text: 'Creates or updates every PPP secret and profile so the router matches billing (suspended users are disabled).', confirmButtonText: 'Sync now' }))
        call('/isp/router-sync-all', { id: r.id }, 'sync' + r.id);
}
async function showSessions(r) {
    Object.assign(sessions, { router: r, rows: [], loading: true });
    try {
        const res = await axios.post('/isp/router-sessions', { id: r.id });
        sessions.rows = res.data;
    } catch (err) {
        showError(err);
    } finally {
        sessions.loading = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-1 text-base font-semibold text-slate-800">{{ form.id ? `Edit router ${form.name}` : 'Add MikroTik router' }}</h1>
            <p class="mb-3 text-xs text-slate-500">After saving, use <b>Setup</b> on the router for a live check and the exact commands. RouterOS v7 REST API (IP → Services → www or www-ssl must be enabled). Packages become PPP / hotspot user profiles with their speed as rate-limit; each PPPoE or Hotspot connection becomes a router user that is disabled on suspension. Hotspot also needs a hotspot server on the LAN interface (IP → Hotspot → Hotspot Setup).</p>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-6" @submit.prevent="save">
                <div><label class="mb-1 block text-xs font-medium text-slate-600">Name</label><input v-model="form.name" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" /></div>
                <div><label class="mb-1 block text-xs font-medium text-slate-600">Host / IP</label><input v-model="form.host" required placeholder="192.168.88.1" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" /></div>
                <div><label class="mb-1 block text-xs font-medium text-slate-600">Port <span class="text-slate-400">(optional)</span></label><input v-model="form.port" type="number" placeholder="80 / 443" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" /></div>
                <div><label class="mb-1 block text-xs font-medium text-slate-600">API user</label><input v-model="form.username" required autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" /></div>
                <div><label class="mb-1 block text-xs font-medium text-slate-600">Password</label><input v-model="form.password" type="password" autocomplete="new-password" :required="!form.id" :placeholder="form.id ? 'Leave blank to keep' : ''" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" /></div>
                <div class="flex flex-col justify-end gap-1 text-sm text-slate-600">
                    <label class="flex items-center gap-2"><input v-model="form.use_https" type="checkbox" /> HTTPS</label>
                    <label class="flex items-center gap-2"><input v-model="form.is_default" type="checkbox" /> Default for branch</label>
                    <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" /> Active</label>
                </div>
                <div class="col-span-2 flex justify-end gap-2 md:col-span-6">
                    <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm text-white" @click="Object.assign(form, blank())">Reset</button>
                    <button type="submit" :disabled="busy === 'save'" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ form.id ? 'Update' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                        <th class="px-3 py-2 font-medium">Router</th>
                        <th class="px-3 py-2 font-medium">Address</th>
                        <th class="px-3 py-2 text-right font-medium">PPPoE / Hotspot users</th>
                        <th class="px-3 py-2 font-medium">Last check</th>
                        <th class="px-3 py-2 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in routers" :key="r.id" class="border-b border-slate-100">
                        <td class="px-3 py-2">{{ r.name }} <span v-if="r.is_default" class="ml-1 rounded-full border border-brand-500 px-1.5 text-[10px] text-brand-600">default</span> <span v-if="!r.is_active" class="text-xs text-red-500">inactive</span></td>
                        <td class="px-3 py-2 font-mono text-xs">{{ r.use_https ? 'https' : 'http' }}://{{ r.host }}{{ r.port ? ':' + r.port : '' }} · {{ r.username }}</td>
                        <td class="px-3 py-2 text-right">{{ r.connections_count }}</td>
                        <td class="px-3 py-2 text-xs" :class="(r.last_status || '').startsWith('FAILED') ? 'text-red-600' : 'text-slate-500'">{{ r.last_status || 'Never checked' }}<div v-if="r.last_checked_at" class="text-slate-400">{{ fmtDate(r.last_checked_at) }}</div></td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" :disabled="busy === 'test' + r.id" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs" @click="call('/isp/router-test', { id: r.id }, 'test' + r.id)"><i class="bi bi-plug"></i> Test</button>
                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs" @click="showSessions(r)"><i class="bi bi-activity"></i> Online users</button>
                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs" :class="guideRouter?.id === r.id ? 'border-slate-800' : ''" @click="openGuide(r)"><i class="bi bi-journal-check"></i> Setup</button>
                                <button type="button" :disabled="busy === 'sync' + r.id" class="rounded-md border border-brand-500 px-2.5 py-1 text-xs text-brand-600" @click="syncAll(r)"><i class="bi bi-arrow-repeat"></i> Sync all</button>
                                <i class="bi bi-pen cursor-pointer self-center text-brand-500" @click="edit(r)"></i>
                                <i class="bi bi-trash cursor-pointer self-center text-red-500" @click="remove(r)"></i>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!routers.length"><td colspan="5" class="px-3 py-6 text-center text-slate-400">No routers yet — billing works without one; suspension then has to be done on the router by hand.</td></tr>
                </tbody>
            </table>
        </div>

        <div ref="guideEl"><RouterSetupGuide v-if="guideRouter" :router="guideRouter" @close="guideId = null" /></div>

        <div v-if="sessions.router" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Online users on {{ sessions.router.name }} <span class="font-normal text-slate-400">({{ sessions.rows.length }})</span></h2>
                <button type="button" class="text-xs text-brand-600" @click="showSessions(sessions.router)"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
            <div v-if="sessions.loading" class="py-4 text-center text-sm text-slate-400">Loading...</div>
            <table v-else class="w-full text-sm">
                <thead><tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600"><th class="px-2 py-1.5 font-medium">Type</th><th class="px-2 py-1.5 font-medium">User</th><th class="px-2 py-1.5 font-medium">Address</th><th class="px-2 py-1.5 font-medium">MAC</th><th class="px-2 py-1.5 font-medium">Uptime</th><th class="px-2 py-1.5 font-medium">Billing</th></tr></thead>
                <tbody>
                    <tr v-for="s in sessions.rows" :key="s.type + s.id" class="border-b border-slate-100">
                        <td class="px-2 py-1.5 text-xs">{{ s.type === 'hotspot' ? 'Hotspot' : 'PPPoE' }}</td>
                        <td class="px-2 py-1.5 font-mono text-xs">{{ s.user }}</td>
                        <td class="px-2 py-1.5 font-mono text-xs">{{ s.address }}</td>
                        <td class="px-2 py-1.5 font-mono text-xs">{{ s.mac }}</td>
                        <td class="px-2 py-1.5">{{ s.uptime }}</td>
                        <td class="px-2 py-1.5">
                            <template v-if="s.connection"><Link :href="`/isp/customer/${s.connection.customer?.id}`" class="text-brand-600 hover:underline">{{ s.connection.customer?.name }}</Link> <StatusBadge :status="s.connection.status" /></template>
                            <span v-else class="text-xs text-amber-700">Not in billing</span>
                        </td>
                    </tr>
                     <tr v-if="!sessions.rows.length"><td colspan="6" class="px-2 py-4 text-center text-slate-400">No one online</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
