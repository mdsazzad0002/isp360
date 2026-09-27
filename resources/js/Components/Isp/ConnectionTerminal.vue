<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import axios from 'axios';
import { useToast } from '../../lib/toast';
import BtestChart from './BtestChart.vue';

// Diagnostic terminal for one connection (commands run on the router through the
// server; see NetworkTerminalService). Values from the connection can be copied or
// dropped into the prompt with one click.
const props = defineProps({
    connections: { type: Array, default: () => [] },
    connectionId: { type: [Number, null], default: null },
    height: { type: String, default: '420px' }, // screen height, e.g. 'calc(100vh - 260px)' in a panel
});
const emit = defineEmits(['ran', 'context']);
const toast = useToast();

const selectedId = ref(props.connectionId ?? props.connections[0]?.id ?? null);
const selected = computed(() => props.connections.find((c) => c.id === selectedId.value) || null);
const lines = ref([]);
const input = ref('');
const running = ref(false);
const runningHint = ref('running…');
const history = ref([]);
let historyIndex = -1;
const screen = ref(null);
const inputEl = ref(null);

const QUICK = ['diagnose', 'session', 'verify', 'ping', 'traffic', 'secret', 'log', 'router', 'help']; // kick/sync must be typed
const TONES = { ok: 'text-emerald-400', warn: 'text-amber-300', error: 'text-red-400', muted: 'text-slate-500' };

try {
    history.value = JSON.parse(localStorage.getItem('isp-terminal-history') || '[]');
} catch {
    history.value = [];
}

// Live session IP of the selected connection and the MikroTik it runs on (asked from the server when it is picked).
const sessionIp = ref(null);
const router = ref(null); // { name, host }: the MikroTik that runs every command
// checking | online | offline | error | unmanaged, with the router's reason, so "offline" is never a guess
const sessionState = ref({ status: 'checking', reason: null });
async function loadSession() {
    sessionIp.value = null;
    router.value = null;
    const c = selected.value;
    if (!c) return;
    if (!['pppoe', 'hotspot'].includes(c.connection_type)) {
        sessionState.value = { status: 'unmanaged', reason: 'static IP / DHCP has no router session' }; // uses the static IP, if any
        return;
    }
    sessionState.value = { status: 'checking', reason: null };
    try {
        const res = await axios.post('/isp/connection-online', { id: c.id });
        if (selected.value?.id !== c.id) return;
        const d = res.data;
        sessionIp.value = d.session?.address || null;
        if (d.router) router.value = { name: d.router, host: d.router_host || null };
        if (!d.managed) sessionState.value = { status: 'unmanaged', reason: 'No router (or no username) for this connection.' };
        else if (d.error) sessionState.value = { status: 'error', reason: d.error };
        else sessionState.value = d.online ? { status: 'online', reason: null } : { status: 'offline', reason: d.reason || null };
    } catch (e) {
        if (selected.value?.id === c.id) sessionState.value = { status: 'error', reason: e.response?.data?.message || e.message };
    }
}

// Real values of the selected connection, used by the chips and to fill the docs examples.
const context = computed(() => {
    const c = selected.value;
    if (!c) return {};
    return {
        user: c.pppoe_username || null,
        sessionIp: sessionIp.value,
        staticIp: c.static_ip || null,
        ip: sessionIp.value || c.static_ip || null,
        mac: c.mac_address || null,
        code: c.code,
        profile: c.package ? c.package.network_profile || `isp-pkg-${c.package.id ?? c.package_id}` : null,
        routerName: router.value?.name || null,
        routerIp: router.value?.host || null,
        sessionStatus: sessionState.value.status,
        sessionReason: sessionState.value.reason,
    };
});
watch(context, (ctx) => emit('context', ctx), { immediate: true, deep: true });

// Copyable values of the selected connection.
const values = computed(() => {
    const c = context.value;
    return [
        ['router ip', c.routerIp],
        ['username', c.user],
        ['session ip', c.sessionIp],
        ['static ip', c.staticIp],
        ['mac', c.mac],
        ['profile', c.profile],
        ['code', c.code],
    ].filter(([, v]) => v);
});

function print(text, tone = null) {
    lines.value.push({ text, tone });
}
async function scrollDown() {
    await nextTick();
    if (screen.value) screen.value.scrollTop = screen.value.scrollHeight;
}

// Passwords typed into a command (btest password=...) are hidden on screen and in history.
const maskSecrets = (cmd) => cmd.replace(/\b(password|secret)=("[^"]*"|\S+)/gi, '$1=••••');

async function run(command = input.value) {
    command = String(command || '').trim();
    if (!command || running.value || !selectedId.value) return;
    input.value = '';
    historyIndex = -1;
    const safe = maskSecrets(command);
    history.value = [safe, ...history.value.filter((h) => h !== safe)].slice(0, 50);
    try {
        localStorage.setItem('isp-terminal-history', JSON.stringify(history.value));
    } catch {
        /* history is a convenience only */
    }

    print(`${selected.value?.pppoe_username || selected.value?.code}$ ${safe}`, 'prompt');
    if (command === 'clear') {
        lines.value = [];
        return;
    }
    running.value = true;
    // a bandwidth test blocks for its whole duration
    const bt = /^(btest|\/?tool\s+bandwidth-test|bandwidth-test)\b/i.test(command);
    const secs = Math.min(15, Math.max(1, parseInt((command.match(/duration=(\d+)/i) || [])[1] || '5', 10)));
    runningHint.value = bt ? `bandwidth test running for ~${secs}s, loading the line…` : 'running…';
    scrollDown();
    try {
        const res = await axios.post('/isp/connection-terminal', { id: selectedId.value, command });
        res.data.lines.forEach((l) => lines.value.push(l)); // a line may carry a chart (btest)
        const verb = command.split(/\s+/)[0].toLowerCase();
        emit('ran', verb);
        if (['session', 'kick', 'sync', 'diagnose', 'diag'].includes(verb)) loadSession();
    } catch (err) {
        print(err.response?.data?.message || err.message || 'Request failed', 'error');
    } finally {
        running.value = false;
        scrollDown();
        nextTick(() => inputEl.value?.focus());
    }
}

function onKey(e) {
    if (e.key === 'ArrowUp' && history.value.length) {
        historyIndex = Math.min(historyIndex + 1, history.value.length - 1);
        input.value = history.value[historyIndex];
        e.preventDefault();
    } else if (e.key === 'ArrowDown') {
        historyIndex = Math.max(historyIndex - 1, -1);
        input.value = historyIndex >= 0 ? history.value[historyIndex] : '';
        e.preventDefault();
    } else if (e.key === 'l' && e.ctrlKey) {
        lines.value = [];
        e.preventDefault();
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copied');
    } catch {
        toast.error('Copy is blocked by the browser');
    }
}
function insert(text) {
    input.value = input.value.trim() ? `${input.value.trim()} ${text}` : text;
    inputEl.value?.focus();
}
// Puts a command in the prompt and selects its first <placeholder> so typing replaces it.
function setInput(text) {
    input.value = text;
    nextTick(() => {
        const el = inputEl.value;
        if (!el) return;
        el.focus();
        const m = /<[a-z]+>/.exec(text);
        if (m) el.setSelectionRange(m.index, m.index + m[0].length);
    });
}
defineExpose({ insert, setInput, run: (cmd) => run(cmd), refresh: () => loadSession() });

function copyOutput() {
    copy(lines.value.map((l) => l.text).join('\n'));
}

watch(
    () => props.connectionId,
    (id) => {
        if (id) selectedId.value = id;
    },
);
watch(selectedId, () => {
    loadSession();
    print(`— connection ${selected.value?.code} (${selected.value?.pppoe_username || selected.value?.connection_type}) —`, 'muted');
    scrollDown();
});

onMounted(() => {
    loadSession();
    print('ISP diagnostic terminal. Commands run on the connection\'s router; sping, port and trace run from this server. Type help.', 'muted');
    if (selected.value) print(`— connection ${selected.value.code} (${selected.value.pppoe_username || selected.value.connection_type}) —`, 'muted');
    inputEl.value?.focus();
});
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <select v-model="selectedId" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.code }} · {{ c.pppoe_username || c.static_ip || c.connection_type }} ({{ c.status }})</option>
            </select>
            <span v-for="[name, value] in values" :key="name" class="inline-flex items-center overflow-hidden rounded-md border border-slate-300 text-xs">
                <button type="button" class="px-2 py-1 font-mono hover:bg-slate-100" :title="`Put ${name} in the prompt`" @click="insert(value)">{{ name }}: {{ value }}</button>
                <button type="button" class="border-s border-slate-300 px-1.5 py-1 text-slate-500 hover:bg-slate-100" title="Copy" @click="copy(value)"><i class="bi bi-copy"></i></button>
            </span>
            <div class="ms-auto flex gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs" title="Copy output" @click="copyOutput"><i class="bi bi-clipboard"></i> Copy output</button>
                <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @click="lines = []"><i class="bi bi-eraser"></i> Clear</button>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-800 bg-slate-950 shadow-inner">
            <div ref="screen" :style="{ height }" class="overflow-y-auto p-3 font-mono text-[12.5px] leading-5 text-slate-200">
                <template v-for="(l, i) in lines" :key="i">
                    <BtestChart v-if="l.chart?.type === 'btest'" :chart="l.chart" />
                    <div v-else class="whitespace-pre-wrap break-all" :class="l.tone === 'prompt' ? 'mt-1 text-sky-300' : TONES[l.tone] || ''">{{ l.text }}</div>
                </template>
                <div v-if="running" class="animate-pulse text-slate-500">{{ runningHint }}</div>
            </div>
            <form class="flex items-center gap-2 border-t border-slate-800 px-3 py-2 font-mono text-[13px]" @submit.prevent="run()">
                <span class="shrink-0 text-emerald-400">{{ selected?.pppoe_username || selected?.code || 'isp' }}$</span>
                <input
                    ref="inputEl"
                    v-model="input"
                    :disabled="!selectedId"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="type a command, e.g. ping 8.8.8.8"
                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-0"
                    @keydown="onKey"
                />
                <button type="submit" :disabled="running || !input.trim()" class="rounded bg-slate-800 px-2 py-0.5 text-xs text-slate-300 hover:bg-slate-700 disabled:opacity-40">Run ⏎</button>
            </form>
        </div>

        <div class="flex flex-wrap items-center gap-1.5 text-xs">
            <span class="text-slate-400">Quick:</span>
            <button v-for="q in QUICK" :key="q" type="button" :disabled="running || !selectedId" class="rounded-full border border-slate-300 px-2.5 py-0.5 font-mono hover:border-brand-400 hover:text-brand-600 disabled:opacity-40" @click="run(q)">{{ q }}</button>
            <span class="ms-auto text-slate-400">↑/↓ history · Ctrl+L clear</span>
        </div>
    </div>
</template>
