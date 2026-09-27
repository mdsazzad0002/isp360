<script setup>
import { ref, reactive, computed, watch } from 'vue';
import axios from 'axios';
import Offcanvas from '../Offcanvas.vue';
import CommandBlock from './CommandBlock.vue';
import { useApiError } from '../../lib/isp';

// Setup guide for one router: a live, read-only check of what PPPoE customers need on it,
// and the RouterOS commands to add what is missing, filled with this router's values.
// Clicking a failed/warned check opens its fix: why, steps with commands, and how to test it.
// The values typed here are only used to build the commands (kept per router in this browser).
const props = defineProps({ router: { type: Object, required: true } });
const emit = defineEmits(['close']);
const showError = useApiError();

const check = ref(null);
const loading = ref(false);
const fixKey = ref(null); // the check whose fix panel is open (declared before the immediate router watcher uses it)
const opts = reactive({ lan: '', wan: '', gateway: '10.10.10.1', range: '10.10.10.2-10.10.10.254', pool: 'isp-pool', service: 'isp' });
const storeKey = () => `isp-router-guide-${props.router.id}`;

function restore() {
    try {
        Object.assign(opts, JSON.parse(localStorage.getItem(storeKey()) || '{}'));
    } catch {
        /* defaults */
    }
}
watch(opts, () => {
    try {
        localStorage.setItem(storeKey(), JSON.stringify(opts));
    } catch {
        /* preference only */
    }
});

async function run() {
    loading.value = true;
    try {
        check.value = (await axios.post('/isp/router-readiness', { id: props.router.id })).data;
        const ifaces = check.value.interfaces.filter((i) => i.type !== 'loopback').map((i) => i.name);
        // WAN = the interface with the router's own address; LAN = the next one (or the same on a one-port lab router)
        const wan = check.value.addresses[0]?.interface || ifaces[0] || 'ether1';
        if (!opts.wan) opts.wan = wan;
        if (!opts.lan) opts.lan = check.value.pppoe_servers[0]?.interface || ifaces.find((i) => i !== wan) || wan;
    } catch (err) {
        check.value = null;
        showError(err);
    } finally {
        loading.value = false;
    }
}
watch(
    () => props.router.id,
    () => {
        check.value = null;
        fixKey.value = null;
        Object.assign(opts, { lan: '', wan: '' });
        restore();
        run();
    },
    { immediate: true },
);

const subnet = computed(() => {
    const m = opts.gateway.match(/^(\d+\.\d+\.\d+)\.\d+$/);
    return m ? `${m[1]}.0/24` : '<subnet>';
});
const ports = computed(() => (check.value?.interfaces || []).filter((i) => i.type !== 'loopback'));

// status: ok | bad (customers cannot connect) | warn (works, but something is off) | info
const items = computed(() => {
    const c = check.value;
    if (!c) return [];
    const server = c.pppoe_servers.find((s) => s.enabled);
    const missing = c.profiles.filter((p) => !p.remote);
    return [
        { key: 'rest', status: 'ok', title: 'REST API reachable', text: `RouterOS ${c.version} · ${c.board}. The app can manage this router.` },
        server
            ? { key: 'pppoe', status: 'ok', title: 'PPPoE server', text: c.pppoe_servers.map((s) => `${s.service || '(no name)'} on ${s.interface}${s.enabled ? '' : ' (disabled)'}`).join(', ') }
            : { key: 'pppoe', status: 'bad', title: 'PPPoE server', text: c.pppoe_servers.length ? 'All PPPoE servers are disabled.' : 'None: no customer can connect (they get "Timeout waiting for PADO").' },
        c.pools.length
            ? { key: 'pool', status: 'ok', title: 'IP pool', text: c.pools.map((p) => `${p.name} ${p.ranges}`).join(', ') }
            : { key: 'pool', status: 'bad', title: 'IP pool', text: 'None: customers would connect without an IP.' },
        c.default_profile.remote
            ? { key: 'default', status: 'ok', title: '"default" profile addresses', text: `gateway ${c.default_profile.local || '—'}, pool ${c.default_profile.remote}. New package profiles copy these.` }
            : { key: 'default', status: 'warn', title: '"default" profile addresses', text: 'Not set: profiles the app creates for new packages will have no gateway/pool.' },
        !c.profiles.length
            ? { key: 'profiles', status: 'info', title: 'Package profiles', text: 'None yet: the app creates one per package on the first sync.' }
            : missing.length
              ? { key: 'profiles', status: 'bad', title: 'Package profiles', text: `No address/pool on: ${missing.map((p) => p.name).join(', ')}.` }
              : { key: 'profiles', status: 'ok', title: 'Package profiles', text: c.profiles.map((p) => `${p.name} ${p.rate}`).join(', ') },
        c.masquerade
            ? { key: 'nat', status: 'ok', title: 'Internet for customers (NAT)', text: `${c.masquerade} masquerade rule(s).` }
            : { key: 'nat', status: 'warn', title: 'Internet for customers (NAT)', text: 'No masquerade rule: customers connect but may not reach the internet (unless it is routed upstream).' },
        { key: 'bw', status: c.bandwidth_server ? 'ok' : 'info', title: 'Bandwidth-test server', text: c.bandwidth_server ? 'Enabled: other routers can btest against this one.' : 'Off. Only needed as a btest target.' },
        { key: 'online', status: 'info', title: 'Online now', text: `${c.online} PPPoE session(s).` },
    ];
});
const ICON = { ok: 'bi-check-circle-fill text-emerald-600', bad: 'bi-x-circle-fill text-red-600', warn: 'bi-exclamation-triangle-fill text-amber-500', info: 'bi-info-circle text-slate-400' };
const problems = computed(() => items.value.filter((i) => i.status === 'bad').length);
// all green (only OK / info lines): no list, just "Ready", with the details one click away
const attention = computed(() => items.value.filter((i) => i.status === 'bad' || i.status === 'warn').length);
const showAll = ref(false);

// ---- fixes: one per check. steps = [text, code?]; test = [text, code, expect]
const FIXES = computed(() => {
    const c = check.value || {};
    const disabled = (c.pppoe_servers || []).length && !(c.pppoe_servers || []).some((s) => s.enabled);
    const r = props.router;
    return {
        rest: {
            title: 'Let the app in (REST API)',
            why: 'The app talks to the router over its REST API (the www service). If the service is off, the port is wrong or the user cannot log in, nothing else can be checked or pushed.',
            steps: [
                ['Turn on the www service (REST lives on it) on the port saved here.', `/ip service set www disabled=no port=${r.port || 80}`],
                ['Give the app its own user (full rights), then save that user and password on this router here.', '/user add name=isp-api group=full password=CHANGE-ME'],
                ['If a firewall filters input, allow the app server to reach the port.', `/ip firewall filter add chain=input protocol=tcp dst-port=${r.port || 80} action=accept comment="ISP app REST" place-before=0`],
            ],
            tests: [
                ['From the app server (Linux): must print JSON with "version".', `curl -s -u '${r.username}:<password>' ${r.use_https ? 'https' : 'http'}://${r.host}${r.port ? ':' + r.port : ''}/rest/system/resource`, '{"architecture-name":…,"version":"7.x"…}'],
                ['On the router: the www service is enabled.', '/ip service print where name=www', 'a row without the X (disabled) flag, with the right port'],
            ],
        },
        pppoe: {
            title: 'PPPoE server',
            why: `A customer router first shouts "any PPPoE server here?" (PADI) on the cable. Only a PPPoE server on that port answers (PADO). Without it the customer sees "Timeout waiting for PADO" and never gets to the username/password. The server goes on the port customers are plugged into: ${opts.lan || 'the LAN port'}.`,
            steps: disabled
                ? [['The server exists but is disabled: enable it.', '/interface pppoe-server server enable [find]']]
                : [
                      ['Needs an IP pool first (see "IP pool"). Then add the server on the customer port.', `/interface pppoe-server server add service-name=${opts.service} interface=${opts.lan} default-profile=default one-session-per-host=yes disabled=no`],
                      ['If the customer port is a bridge member, put the server on the bridge instead (interface=bridge…).', null],
                  ],
            tests: [
                ['On the router: one row, not disabled (no X), on the right port.', '/interface pppoe-server server print', `service-name="${opts.service}" interface=${opts.lan}`],
                ['From a Linux machine on that port: the router must answer.', 'sudo pppoe-discovery -I eth0', 'Access-Concentrator: <router identity>'],
            ],
        },
        pool: {
            title: 'IP pool',
            why: `Every customer who logs in needs an IP address. The pool is the range handed out (${opts.range}); the gateway ${opts.gateway} is the router's side of each PPPoE link and must not be inside the range.`,
            steps: [['Add the pool.', `/ip pool add name=${opts.pool} ranges=${opts.range}`]],
            tests: [
                ['The pool exists with the right range.', '/ip pool print', `${opts.pool}  ${opts.range}`],
                ['After a customer connects: their address comes from it.', '/ip pool used print', `${opts.pool}  10.x.x.x  <username>`],
            ],
        },
        default: {
            title: '"default" profile addresses',
            why: 'Each package is a PPP profile. When the app creates a profile for a new package it copies the gateway and pool from the router\'s "default" profile. If "default" has none, every new package gives customers no IP.',
            steps: [['Set gateway and pool on "default" (needs the pool).', `/ppp profile set [find name=default] local-address=${opts.gateway} remote-address=${opts.pool}`]],
            tests: [['Shows both addresses.', '/ppp profile print detail where name=default', `local-address=${opts.gateway} remote-address=${opts.pool}`]],
        },
        profiles: {
            title: 'Package profiles',
            why: `These profiles were made (by the app) before "default" had addresses: customers on them log in but get no IP. ${(c.profiles || []).filter((p) => !p.remote).map((p) => p.name).join(', ')}`,
            steps: [['Give every package profile made by the app the gateway and pool (it only touches profiles with the "ISP package" comment).', `/ppp profile set [find where comment~"^ISP package"] local-address=${opts.gateway} remote-address=${opts.pool}`]],
            tests: [['Every row shows local-address and remote-address.', '/ppp profile print detail where comment~"^ISP package"', `local-address=${opts.gateway} remote-address=${opts.pool} rate-limit=…`]],
        },
        nat: {
            title: 'Internet for customers (NAT)',
            why: `Customers get private addresses (${subnet.value}). To reach the internet they must leave through the router's WAN port (${opts.wan || 'WAN'}) under the router's own address (masquerade). Not needed if your upstream routes this range back to you.`,
            steps: [
                ['Masquerade the customer range on the WAN port.', `/ip firewall nat add chain=srcnat src-address=${subnet.value} out-interface=${opts.wan} action=masquerade comment="ISP customers"`],
                ['The router itself needs internet: there must be a default route.', '/ip route print where dst-address=0.0.0.0/0'],
            ],
            tests: [
                ['The rule exists and counts packets once customers browse.', '/ip firewall nat print stats where comment="ISP customers"', 'packets/bytes going up'],
                ['The router reaches the internet.', '/ping 8.8.8.8 count=3', '3 received'],
                ['From a connected test customer (Linux, pppd running).', 'ping -I ppp0 -c 3 8.8.8.8', '3 received'],
            ],
        },
        bw: {
            title: 'Bandwidth-test server',
            why: 'Only needed when another MikroTik should run a bandwidth test against this router. The terminal\'s btest runs FROM this router towards the customer\'s CPE, which needs it on the CPE, not here.',
            steps: [['Enable it (with login).', '/tool bandwidth-server set enabled=yes authenticate=yes']],
            tests: [['From another MikroTik.', `/tool bandwidth-test address=${r.host} user=${r.username} password=<password> direction=both duration=5s`, 'status: running, then done testing with tx/rx averages']],
        },
    };
});
const fix = computed(() => (fixKey.value ? FIXES.value[fixKey.value] : null));
const fixItem = computed(() => items.value.find((i) => i.key === fixKey.value) || null);
// Tabs in the fix panel: every check that has a fix and is not OK yet (plus the one open, even once it turns green)
const fixTabs = computed(() => items.value.filter((i) => clickable(i) || i.key === fixKey.value));
const nextProblem = computed(() => {
    const open = fixTabs.value.filter((i) => i.status === 'bad' || i.status === 'warn');
    const at = open.findIndex((i) => i.key === fixKey.value);
    return open[(at + 1) % open.length]?.key !== fixKey.value ? open[(at + 1) % open.length] : null;
});
// problems open their fix; so does the optional bandwidth-test server while it is off
const clickable = (i) => !!FIXES.value[i.key] && (i.status === 'bad' || i.status === 'warn' || (i.key === 'bw' && i.status === 'info'));

const allCommands = computed(() =>
    [
        FIXES.value.pool.steps[0][1],
        FIXES.value.default.steps[0][1],
        `/interface pppoe-server server add service-name=${opts.service} interface=${opts.lan} default-profile=default one-session-per-host=yes disabled=no`,
        FIXES.value.profiles.steps[0][1],
        FIXES.value.nat.steps[0][1],
    ].join('\n'),
);
const undoCommands = computed(() =>
    [
        `/interface pppoe-server server remove [find service-name=${opts.service}]`,
        '/ppp profile set [find name=default] !local-address !remote-address',
        '/ppp profile set [find where comment~"^ISP package"] !local-address !remote-address',
        `/ip pool remove [find name=${opts.pool}]`,
        '/ip firewall nat remove [find comment="ISP customers"]',
    ].join('\n'),
);
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-slate-800"><i class="bi bi-journal-check"></i> Setup guide · {{ router.name }} <span class="font-mono text-xs font-normal text-slate-500">({{ router.host }})</span></h2>
                <p class="text-xs text-slate-500">What PPPoE customers need on this router. The check only reads the router; you run the commands yourself (WinBox → New Terminal, or SSH). Click a <i class="bi bi-x-circle-fill text-red-600"></i> or <i class="bi bi-exclamation-triangle-fill text-amber-500"></i> line to see how to fix it.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" :disabled="loading" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs disabled:opacity-50" @click="run"><i class="bi bi-arrow-clockwise" :class="loading ? 'animate-spin' : ''"></i> Check again</button>
                <button type="button" class="text-slate-400 hover:text-slate-600" title="Close" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <div v-if="loading && !check" class="py-4 text-center text-sm text-slate-400">Reading the router…</div>
        <div v-else-if="!check" class="flex items-center justify-between gap-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">
            <span><i class="bi bi-x-circle-fill"></i> The router did not answer: the app cannot reach its REST API.</span>
            <button type="button" class="rounded border border-red-300 px-2 py-0.5 font-medium hover:bg-white" @click="fixKey = 'rest'">How to fix <i class="bi bi-chevron-right"></i></button>
        </div>
        <template v-else>
            <div v-if="!attention" class="mb-3 flex items-center justify-between gap-2 rounded-md bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-800">
                <span><i class="bi bi-check-circle"></i> Ready: everything PPPoE customers need is on this router.</span>
                <button type="button" class="font-normal underline" @click="showAll = !showAll">{{ showAll ? 'Hide details' : 'Details' }}</button>
            </div>
            <div v-else class="mb-3 rounded-md px-3 py-1.5 text-xs font-medium" :class="problems ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800'">
                <i class="bi" :class="problems ? 'bi-x-circle' : 'bi-exclamation-triangle'"></i>
                {{ problems ? `${problems} thing(s) stop customers from connecting. Click them to fix, then Check again.` : `Customers can connect; ${attention} warning(s) left. Click to see why.` }}
            </div>
            <ul v-if="attention || showAll" class="mb-4 divide-y divide-slate-100 rounded-md border border-slate-200 text-xs">
                <li v-for="i in items" :key="i.key">
                    <button v-if="clickable(i)" type="button" class="flex w-full gap-2 px-3 py-1.5 text-start hover:bg-slate-50" @click="fixKey = i.key">
                        <i class="bi mt-px" :class="ICON[i.status]"></i>
                        <span class="w-48 shrink-0 font-medium text-slate-700">{{ i.title }}</span>
                        <span class="flex-1 text-slate-600">{{ i.text }}</span>
                        <span class="shrink-0 font-medium text-brand-600">How to fix <i class="bi bi-chevron-right"></i></span>
                    </button>
                    <div v-else class="flex gap-2 px-3 py-1.5">
                        <i class="bi mt-px" :class="ICON[i.status]"></i>
                        <span class="w-48 shrink-0 font-medium text-slate-700">{{ i.title }}</span>
                        <span class="text-slate-600">{{ i.text }}</span>
                    </div>
                </li>
            </ul>

            <details v-if="attention || showAll" class="text-xs">
                <summary class="cursor-pointer text-slate-500 hover:text-slate-800">All setup commands at once / lab clean-up</summary>
                <div class="mt-2 space-y-2">
                    <CommandBlock label="MikroTik terminal · full PPPoE setup (pool, default profile, server, package profiles, NAT)" :code="allCommands" />
                    <CommandBlock label="MikroTik terminal · undo" :code="undoCommands" />
                    <p class="text-slate-500">Test as a real customer: Connections → a PPPoE connection → <b>Test dial</b>.</p>
                </div>
            </details>
        </template>
    </div>

    <Offcanvas :show="!!fix" width="sm:w-[70vw]" @close="fixKey = null">
        <template v-if="fix">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-base font-semibold text-slate-800"><i class="bi bi-wrench-adjustable"></i> Fix: {{ fix.title }} <span class="text-xs font-normal text-slate-500">· {{ router.name }}</span></h2>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="fixKey = null"><i class="bi bi-x-lg"></i></button>
            </div>
            <div v-if="fixTabs.length > 1" class="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 pt-2">
                <button
                    v-for="t in fixTabs"
                    :key="t.key"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-t-md border border-b-0 px-3 py-1.5 text-xs"
                    :class="t.key === fixKey ? 'border-slate-200 bg-white font-semibold text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    @click="fixKey = t.key"
                ><i class="bi" :class="ICON[t.status]"></i>{{ FIXES[t.key].title }}</button>
            </div>
            <div class="flex-1 space-y-4 overflow-y-auto p-4 text-sm">
                <div class="flex items-start gap-2 rounded-md px-3 py-2 text-xs" :class="fixItem?.status === 'ok' ? 'bg-emerald-50 text-emerald-800' : fixItem?.status === 'warn' ? 'bg-amber-50 text-amber-800' : fixItem?.status === 'info' ? 'bg-slate-50 text-slate-700' : 'bg-red-50 text-red-700'">
                    <i class="bi mt-px" :class="fixItem ? ICON[fixItem.status] : ICON.bad"></i>
                    <div class="flex-1"><b>Now:</b> {{ fixItem ? (fixItem.status === 'ok' ? 'Fixed. ' : '') + fixItem.text : 'The router does not answer.' }}</div>
                    <button type="button" :disabled="loading" class="shrink-0 rounded border border-current px-2 py-0.5 font-medium hover:bg-white disabled:opacity-50" @click="run"><i class="bi bi-arrow-clockwise" :class="loading ? 'animate-spin' : ''"></i> Check again</button>
                </div>

                <div>
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Why</div>
                    <p class="text-slate-700">{{ fix.why }}</p>
                </div>

                <div v-if="check && fixKey !== 'rest' && fixKey !== 'bw'" class="rounded-md border border-slate-200 p-2">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Values in the commands</div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <label class="text-xs text-slate-600">Customer (LAN) port
                            <select v-model="opts.lan" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1 text-sm"><option v-for="i in ports" :key="i.name" :value="i.name">{{ i.name }}{{ i.running ? '' : ' (down)' }}</option></select>
                        </label>
                        <label class="text-xs text-slate-600">Internet (WAN) port
                            <select v-model="opts.wan" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1 text-sm"><option v-for="i in ports" :key="i.name" :value="i.name">{{ i.name }}</option></select>
                        </label>
                        <label class="text-xs text-slate-600">Gateway IP<input v-model.trim="opts.gateway" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1 font-mono text-sm" /></label>
                        <label class="col-span-2 text-xs text-slate-600">Customer IP range<input v-model.trim="opts.range" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1 font-mono text-sm" /></label>
                        <label class="text-xs text-slate-600">Pool name<input v-model.trim="opts.pool" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1 font-mono text-sm" /></label>
                    </div>
                    <p v-if="opts.lan === opts.wan" class="mt-1 text-xs text-amber-700"><i class="bi bi-info-circle"></i> LAN and WAN are the same port: fine for a one-port lab router, not for a real network.</p>
                </div>

                <div>
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Solve</div>
                    <ol class="space-y-2">
                        <li v-for="([text, code], n) in fix.steps" :key="n" class="flex gap-2">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[11px] font-semibold text-white">{{ n + 1 }}</span>
                            <div class="min-w-0 flex-1 space-y-1">
                                <p class="text-slate-700">{{ text }}</p>
                                <CommandBlock v-if="code" :code="code" label="MikroTik terminal · copy and run as is" />
                            </div>
                        </li>
                    </ol>
                </div>

                <div>
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Test</div>
                    <div class="space-y-2">
                        <div v-for="([text, code, expect], n) in fix.tests" :key="n" class="space-y-1">
                            <p class="text-slate-700">{{ text }}</p>
                            <CommandBlock :code="code" :label="code.startsWith('/') ? 'MikroTik terminal' : 'Linux'" />
                            <div class="rounded-md border border-dashed border-emerald-300 bg-emerald-50/60 px-2 py-1 text-xs">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-emerald-700"><i class="bi bi-eye"></i> The output should show · not a command, do not run it</div>
                                <div class="font-mono text-slate-700">{{ expect }}</div>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500">Then press <b>Check again</b> above: this line turns green when the router has it.</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-slate-200 px-4 py-2 text-xs">
                <span class="text-slate-500">{{ problems ? `${fixTabs.filter((t) => t.status === 'bad' || t.status === 'warn').length} problem(s) left on ${router.name}` : 'No blocking problems left.' }}</span>
                <button v-if="nextProblem" type="button" class="rounded-md bg-slate-900 px-3 py-1 font-medium text-white hover:bg-slate-700" @click="fixKey = nextProblem.key">Next: {{ FIXES[nextProblem.key].title }} <i class="bi bi-chevron-right"></i></button>
                <button v-else type="button" class="rounded-md border border-slate-300 px-3 py-1" @click="fixKey = null">Close</button>
            </div>
        </template>
    </Offcanvas>
</template>
