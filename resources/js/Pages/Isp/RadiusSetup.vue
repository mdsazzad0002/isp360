<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import CommandBlock from '../../Components/Isp/CommandBlock.vue';
import { useToast } from '../../lib/toast';
import { useApiError, fmtDateTime } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const s = ref(null);
const server = reactive({ radius_server: '', radius_auth_port: 1812, radius_self_ip: '127.0.0.1' });
const busy = ref(null);
const test = ref(null); // { ok, message }
const script = ref(null); // installer with the password
const revealed = reactive({}); // router id => commands with the secret

async function load() {
    s.value = (await axios.post('/isp/get-radius-setup')).data;
    Object.assign(server, { radius_server: s.value.server.host || '', radius_auth_port: s.value.server.auth_port, radius_self_ip: s.value.server.self_ip });
}
onMounted(load);

async function act(key, url, payload = {}) {
    busy.value = key;
    try {
        toast.success((await axios.post(url, payload)).data.message);
        await load();
        return true;
    } catch (err) {
        showError(err);
        return false;
    } finally {
        busy.value = null;
    }
}
async function runTest() {
    busy.value = 'test';
    test.value = null;
    try {
        test.value = { ok: true, message: (await axios.post('/isp/radius-test')).data.message };
    } catch (err) {
        test.value = { ok: false, message: err?.response?.data?.message || 'Failed' };
    } finally {
        busy.value = null;
        load();
    }
}
async function saveServer() {
    if (await act('server', '/isp/radius-server', { ...server })) runTest();
}
async function showScript() {
    try {
        script.value = (await axios.post('/isp/radius-reveal', { what: 'installer' })).data.script;
    } catch (err) {
        showError(err);
    }
}
async function revealRouter(r) {
    try {
        revealed[r.id] = (await axios.post('/isp/radius-reveal', { what: 'router', id: r.id })).data;
    } catch (err) {
        showError(err);
    }
}

// the steps in order, each green / red
const steps = computed(() => {
    if (!s.value) return [];
    const x = s.value;
    return [
        { key: 'db', ok: !x.db_error, label: 'RADIUS database reachable', detail: x.db_error || `${x.database.database} on ${x.database.host}${x.database.same_as_app ? ' (the app database)' : ''}` },
        { key: 'tables', ok: !x.db_error && !x.missing_tables.length, label: 'FreeRADIUS tables', detail: x.missing_tables.length ? `missing: ${x.missing_tables.join(', ')}` : 'all there' },
        { key: 'server', ok: test.value?.ok === true, pending: !test.value, label: 'FreeRADIUS answers logins', detail: test.value?.message || (x.server.host ? 'Press Test login' : 'Enter the server below') },
        { key: 'routers', ok: x.routers.length > 0 && x.routers.every((r) => r.registered), pending: !x.routers.length, label: 'Routers registered with FreeRADIUS', detail: x.routers.length ? `${x.routers.filter((r) => r.registered).length} of ${x.routers.length}` : 'No router is set to RADIUS yet (Routers → Managed by: RADIUS)' },
        { key: 'acct', ok: x.routers.some((r) => r.last_accounting), pending: !x.routers.length, label: 'Accounting arriving from routers', detail: x.routers.filter((r) => r.last_accounting).map((r) => `${r.name}: ${fmtDateTime(r.last_accounting)}`).join(' · ') || 'nothing received yet' },
    ];
});
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-1 text-base font-semibold text-slate-800">RADIUS Setup</h1>
            <p class="mb-3 text-xs text-slate-500">Routers ask FreeRADIUS whether a customer may log in; FreeRADIUS reads the answer from the tables billing keeps up to date. Each check below is live; fix the red ones top to bottom.</p>
            <div v-if="!s" class="text-slate-400">Checking…</div>
            <ol v-else class="space-y-2">
                <li v-for="(st, i) in steps" :key="st.key" class="flex items-start gap-2 text-sm">
                    <i class="bi mt-0.5" :class="st.ok ? 'bi-check-circle-fill text-emerald-600' : st.pending ? 'bi-circle text-slate-400' : 'bi-x-circle-fill text-red-600'"></i>
                    <div class="flex-1">
                        <b>{{ i + 1 }}. {{ st.label }}</b>
                        <div class="whitespace-pre-line text-xs text-slate-500">{{ st.detail }}</div>
                    </div>
                    <button v-if="st.key === 'tables' && !st.ok && !s.db_error" type="button" :disabled="!!busy" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="act('tables', '/isp/radius-create-tables')">Create tables</button>
                    <button v-if="st.key === 'server' && s.server.host" type="button" :disabled="!!busy" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="runTest">{{ busy === 'test' ? 'Testing…' : 'Test login' }}</button>
                    <button v-if="st.key === 'routers' && s.routers.length && !st.ok" type="button" :disabled="!!busy" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="act('register', '/isp/radius-register-all')">Register all</button>
                </li>
            </ol>
            <p v-if="s?.db_error" class="mt-2 text-xs text-slate-500">The RADIUS database is set with RADIUS_DB_HOST / _DATABASE / _USERNAME / _PASSWORD in .env (empty = the app database).</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">FreeRADIUS server</h2>
            <form class="grid grid-cols-1 gap-2 md:grid-cols-4" @submit.prevent="saveServer">
                <label class="text-xs text-slate-600">Server address (as this server reaches it)<input v-model="server.radius_server" required placeholder="127.0.0.1 or 10.0.0.5" :class="input" /></label>
                <label class="text-xs text-slate-600">Auth port<input v-model="server.radius_auth_port" type="number" min="1" max="65535" :class="input" /></label>
                <label class="text-xs text-slate-600">This server's IP as FreeRADIUS sees it<input v-model="server.radius_self_ip" placeholder="127.0.0.1" :class="input" /></label>
                <div class="flex items-end"><button type="submit" :disabled="!!busy" class="w-full rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Save &amp; test</button></div>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">Install FreeRADIUS (once)</h2>
            <p class="mb-2 text-xs text-slate-500">
                On the FreeRADIUS server (Ubuntu / Debian), as root: download this script and run <code>sudo bash freeradius-setup.sh</code>.
                It installs FreeRADIUS 3, points it at the billing database, and installs a watcher that restarts FreeRADIUS by itself whenever you add or change a router here, so nothing has to be done on that server again.
            </p>
            <div class="flex flex-wrap gap-2">
                <a href="/isp/radius-installer?password=1" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white"><i class="bi bi-download"></i> Download script</a>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="script ? (script = null) : showScript()"><i class="bi bi-eye"></i> {{ script ? 'Hide' : 'Show' }} script (has the database password, audited)</button>
            </div>
            <div v-if="script" class="mt-2"><CommandBlock :code="script" label="freeradius-setup.sh" /></div>
        </div>

        <div v-if="s?.routers.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">Routers set to RADIUS</h2>
            <div class="space-y-3">
                <div v-for="r in s.routers" :key="r.id" class="rounded-md border border-slate-200 p-3">
                    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <b>{{ r.name }}</b>
                        <span class="font-mono text-xs text-slate-500">{{ r.host }}</span>
                        <span class="rounded px-1.5 text-xs" :class="r.registered ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">{{ r.registered ? 'registered' : r.has_secret ? 'not registered' : 'no secret set' }}</span>
                        <span class="text-xs text-slate-500">{{ r.online }} online<span v-if="r.last_accounting"> · last accounting {{ fmtDateTime(r.last_accounting) }}</span></span>
                        <button type="button" class="ms-auto text-xs text-brand-600 hover:underline" @click="revealRouter(r)"><i class="bi bi-eye"></i> Show with secret (audited)</button>
                    </div>
                    <CommandBlock :code="(revealed[r.id] || r.commands).lines.join('\n')" :label="(revealed[r.id] || r.commands).kind === 'script' ? `Paste in the ${r.nas_type === 'mikrotik' ? 'MikroTik terminal' : 'router CLI'}` : 'Set these on the router'" />
                </div>
            </div>
        </div>
    </div>
</template>
