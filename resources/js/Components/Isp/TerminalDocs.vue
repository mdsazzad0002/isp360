<script setup>
import { ref, computed } from 'vue';

// Reference for the connection terminal. Every example can be dropped into the prompt
// ("use") or run straight away ("run"); the parent wires both to the terminal.
// Examples are templates filled with the selected connection's real values:
//   {user} username · {ip} live session IP (else static IP) · {profile} package profile · {mac}
//   {router} IP/host of the connection's MikroTik (where commands run; never a btest target)
// A value that is not known stays as <placeholder>; such an example can only be "used".
const props = defineProps({ ctx: { type: Object, default: () => ({}) } });
const emit = defineEmits(['use', 'run']);

function fill(template) {
    return template
        .replace(/\{user\}/g, props.ctx.user || '<user>')
        .replace(/\{ip\}/g, props.ctx.ip || '<ip>')
        .replace(/\{profile\}/g, props.ctx.profile || '<profile>')
        .replace(/\{mac\}/g, props.ctx.mac || '<mac>')
        .replace(/\{router\}/g, props.ctx.routerIp || '<router>');
}
const needsInput = (cmd) => /<[a-z]+>/.test(cmd);
const search = ref('');
const section = ref('start');

const COMMANDS = [
    {
        name: 'diagnose',
        syntax: 'diagnose [user]',
        summary: 'Runs the standard first-line check in one go.',
        details: 'Prints the connection details, compares the router with billing (verify), shows the live session, pings the customer IP and prints the last router log lines about the user. Ends with a one-line summary of what to do next.',
        examples: ['diagnose', 'diag {user}'],
        output: 'Sections start with "==". Read the summary line at the end first.',
    },
    {
        name: 'session',
        syntax: 'session [user]',
        summary: 'Is the customer online on the router right now?',
        details: 'Looks the username up in /ppp/active (PPPoE) or /ip/hotspot/active (hotspot).',
        examples: ['session', 'session {user}'],
        output: 'ONLINE with address (customer IP), caller-id / mac-address (customer router MAC), uptime (time since the last connect). OFFLINE means no live session.',
        tips: ['Uptime of a few seconds that keeps resetting means the customer keeps reconnecting: check cable/ONU power, or duplicate logins.', 'A different MAC than usual may mean the customer changed router, or someone else is using the account.'],
    },
    {
        name: 'verify',
        syntax: 'verify [user]',
        summary: 'Does the router match billing? Updates the "Router sync" status.',
        details: 'Read-only check: the account exists, it is enabled only when the connection is active, it has the package profile, and a suspended user has no live session. The result is saved on the connection and shown as the sync badge everywhere.',
        examples: ['verify', 'verify {user}'],
        output: 'Synced = matches. Router mismatch = listed differences (someone changed the router by hand, or a sync did not finish). Sync failed = router unreachable or refused. Not managed = no router / static IP / DHCP.',
        tips: ['After fixing with sync, run verify again to confirm.'],
    },
    {
        name: 'ping',
        syntax: 'ping [ip|host] [count]',
        summary: 'Ping from the router (not from this server).',
        details: 'With no target it pings the live session IP (or the static IP). Count is 1–10, default 4.',
        examples: ['ping', 'ping {ip}', 'ping {ip} 10', 'ping 8.8.8.8', 'ping google.com 3'],
        output: 'time = round trip per packet. The last line gives sent/received, % loss and min/avg/max.',
        tips: ['0% loss but customer says slow: run traffic to see if the line is full.', '100% loss while ONLINE: customer router may block ping (ICMP). Not always a fault.', 'Loss to 8.8.8.8 too: the problem is upstream, not this customer.'],
    },
    {
        name: 'traffic',
        syntax: 'traffic [user]',
        summary: 'Current download/upload speed of the session.',
        details: 'PPPoE: one sample of /interface monitor-traffic on <pppoe-user>. Hotspot: total bytes of the session.',
        examples: ['traffic', 'traffic {user}'],
        output: 'download = router → customer, upload = customer → router.',
        tips: ['Download near the package speed = the line is full; the customer is using all of it (downloads, CCTV, many devices).', 'Much lower than the package while the customer complains: check the profile with secret and verify.'],
    },
    {
        name: 'secret',
        syntax: 'secret [user]',
        summary: 'The router account (PPP secret / hotspot user).',
        details: 'Shows profile, disabled, last-logged-out, last-caller-id and last-disconnect-reason. Password only with the "View PPPoE Password" permission.',
        examples: ['secret', 'secret {user}'],
        tips: ['disabled=true on an active connection: run verify, then sync.', 'last-disconnect-reason tells why the last session ended (peer disconnected, admin, session timeout...).'],
    },
    {
        name: 'log',
        syntax: 'log [user|all] [lines]',
        summary: 'Router log lines about the user.',
        details: 'Filters the router log for the username. "log all" shows the whole log. Lines default 20, max 100.',
        examples: ['log', 'log 50', 'log {user} 30', 'log all 30'],
        tips: ['"authentication failed" = wrong password on the customer router, or the account is disabled.', 'Many "connected"/"disconnected" pairs = unstable line.'],
    },
    {
        name: 'info',
        syntax: 'info [user]',
        summary: 'What billing knows about the connection.',
        details: 'Customer, type, username, package and its router profile, box, router, and router sync status with last push / last verify times.',
        examples: ['info', 'info {user}'],
    },
    {
        name: 'router',
        syntax: 'router',
        summary: 'Health of the connection\'s router.',
        details: 'Identity, board, RouterOS version, uptime, CPU load, free memory and how many PPP users are online.',
        examples: ['router'],
        tips: ['High CPU (80%+) makes every customer slow, not just one.', 'Short uptime = the router rebooted recently.'],
    },
    {
        name: 'btest',
        syntax: 'btest [address] [user=<u>] [password=<p>] [direction=both|receive|transmit] [duration=5s] [protocol=tcp|udp]',
        summary: 'Bandwidth test from the connection\'s router, drawn as a graph.',
        details: 'Same as RouterOS "/tool bandwidth-test" (you can paste that form too). The test always runs FROM the connection\'s MikroTik; address= is the OTHER end (see "Which IP" above), never the MikroTik itself. With no address it tests to the customer\'s live session IP; with no user/password it logs in with the connection\'s own username and password (never shown or logged). The target must be a MikroTik with /tool bandwidth-server enabled, e.g. the customer\'s MikroTik CPE. Duration is capped at 15 s. Needs "Connection Activate / Suspend" or "Routers (MikroTik)". Audited.',
        examples: ['btest', 'btest direction=both duration=10s', 'btest {ip} direction=both', 'btest {ip} protocol=udp direction=receive', 'btest {ip} user=admin password=<password> direction=both', '/tool bandwidth-test address={ip} direction=both'],
        output: 'A graph of download (router → target) and upload (target → router) per second, with averages and the package speed as a dashed line; "Table" shows the numbers. UDP also reports lost packets.',
        tips: ['address={router} is wrong: that is the MikroTik running the test, so it would test itself.', 'Offline customer = no {ip}: the test needs a live session (or a static IP) to aim at.', 'It really loads the line: run it briefly, not during peak hours.', 'Averages near the package line = the link and profile are fine.', 'Router or target CPU near 100% = the device is the limit, not the line; try protocol=udp.', '"No result" = wrong login, bandwidth-server off on the target, or TCP 2000 blocked. Try user=/password= of the CPE.'],
        danger: true,
    },
    {
        name: 'kick',
        syntax: 'kick [user]',
        summary: 'Disconnect the live session. The customer router reconnects by itself.',
        details: 'Useful after a package change (the new speed applies on reconnect) or to clear a stuck session. Needs the "Connection Activate / Suspend" permission. Audited.',
        examples: ['kick', 'kick {user}'],
        danger: true,
    },
    {
        name: 'sync',
        syntax: 'sync',
        summary: 'Push this connection to the router again.',
        details: 'Creates or updates the account, profile and enabled/disabled state, and kicks the session when access or profile changed. Needs the "Connection Activate / Suspend" permission. Audited.',
        examples: ['sync'],
        danger: true,
    },
    {
        name: 'ros',
        syntax: 'ros <path> [field=value ...]',
        summary: 'Read any RouterOS menu (read-only).',
        details: 'Runs a GET on the RouterOS REST path, with optional exact-match filters. Shows up to 50 rows. Password/secret fields are hidden without the "View PPPoE Password" permission. Needs the "Routers (MikroTik)" permission. Audited.',
        examples: ['ros /ppp/active', 'ros /ppp/active name={user}', 'ros /ppp/secret name={user}', 'ros /ppp/secret profile={profile}', 'ros /ppp/profile name={profile}', 'ros /ip/arp address={ip}', 'ros /interface running=true', 'ros /ip/address', 'ros /queue/simple', 'ros /ip/pool', 'ros /system/health'],
    },
    { name: 'help', syntax: 'help', summary: 'Short list of all commands.', examples: ['help'] },
    { name: 'clear', syntax: 'clear', summary: 'Clear the screen (also Ctrl+L).', examples: ['clear'] },
];

const PLAYBOOKS = [
    {
        title: 'Customer says: no internet',
        steps: [
            ['diagnose', 'Read the summary line first.'],
            ['info', 'Connection must be active in billing. Suspended/overdue = collect payment first.'],
            ['verify', 'Router mismatch or failed → run sync, then verify.'],
            ['session', 'OFFLINE with a correct router = problem at the customer side (ONU/cable/router power).'],
            ['log', '"authentication failed" = wrong password on the customer router.'],
        ],
    },
    {
        title: 'Customer says: internet is slow',
        steps: [
            ['traffic', 'At the package speed = the line is full (their usage).'],
            ['btest duration=10s', 'Only if the customer has a MikroTik CPE with bandwidth-server: graphs the real link speed against the package.'],
            ['secret', 'Profile must be the package\'s profile.'],
            ['ping 8.8.8.8 10', 'Loss or high time here = upstream problem, affects everyone.'],
            ['router', 'High CPU on the router slows all users.'],
        ],
    },
    {
        title: 'Package changed but speed did not',
        steps: [
            ['verify', 'Profile mismatch → sync.'],
            ['kick', 'The live session keeps the old speed until it reconnects.'],
            ['session', 'Confirm it came back (uptime of a few seconds).'],
        ],
    },
    {
        title: 'Suspended customer still has internet',
        steps: [
            ['verify', 'Shows "still has a live session" or "account is ENABLED".'],
            ['sync', 'Disables the account and kicks the session.'],
            ['verify', 'Must say Synced now.'],
        ],
    },
    {
        title: 'Router sync failed / unreachable',
        steps: [
            ['router', 'If this fails too, the server cannot reach the router (network, port, REST service, credentials).'],
            ['info', 'Check which router the connection uses.'],
            ['sync', 'Retry once the router is reachable.'],
        ],
    },
];

const SYNC_STATES = [
    ['Synced', 'The router matched billing at the last push or verify.'],
    ['Sync pending', 'A change was made and the push has not finished yet (or a queue worker is not running).'],
    ['Sync failed', 'The last push failed; hover the badge for the router error.'],
    ['Router mismatch', 'A live verify found differences, e.g. someone changed the router by hand.'],
    ['Not managed', 'Nothing is pushed: no router for this connection, static IP / DHCP, or no network driver.'],
];

const SECTIONS = [
    ['start', 'Start'],
    ['commands', 'Commands'],
    ['playbooks', 'Troubleshooting'],
    ['sync', 'Sync status'],
    ['keys', 'Keys & safety'],
];

const filteredCommands = computed(() => {
    const t = search.value.trim().toLowerCase();
    return t ? COMMANDS.filter((c) => [c.name, c.syntax, c.summary, c.details, ...(c.tips || []), ...(c.examples || []).map(fill)].join(' ').toLowerCase().includes(t)) : COMMANDS;
});
function onSearch() {
    if (search.value.trim()) section.value = 'commands';
}
</script>

<template>
    <div class="flex h-full flex-col text-sm">
        <div class="space-y-2 border-b border-slate-200 p-3">
            <input v-model="search" placeholder="Search docs… (e.g. slow, password, profile)" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" @input="onSearch" />
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="[k, text] in SECTIONS"
                    :key="k"
                    type="button"
                    class="rounded-full px-2.5 py-0.5 text-xs"
                    :class="section === k ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                    @click="section = k"
                >{{ text }}</button>
            </div>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto p-3">
            <div class="rounded-md border border-slate-200 bg-white p-2 text-[11px] text-slate-500">
                Examples use <b class="text-slate-700">{{ ctx.code || 'the selected connection' }}</b>:
                <span class="font-mono">router={{ ctx.routerName ? `${ctx.routerName} (${ctx.routerIp || '—'})` : '—' }} · user={{ ctx.user || '—' }} · ip={{ ctx.ip || '—' }}{{ ctx.sessionIp ? ' (live)' : ctx.staticIp ? ' (static)' : '' }} · profile={{ ctx.profile || '—' }}</span>.
                <span v-if="!ctx.ip">No live IP (offline): examples with <span class="font-mono">&lt;ip&gt;</span> need one.</span>
                Dashed examples need a value (e.g. the btest password) before running.
            </div>
            <template v-if="section === 'start'">
                <p>The terminal runs a fixed set of <b>diagnostic commands on the connection's MikroTik router</b> through its REST API. It is not a shell: nothing runs on the server, and anything not listed here is refused.</p>
                <ol class="list-decimal space-y-1 pl-5">
                    <li>Pick the connection at the top (or open the terminal from a connection's <b>Test</b> button).</li>
                    <li>Start with <button type="button" class="font-mono text-brand-600 hover:underline" @click="emit('run', 'diagnose')">diagnose</button>: it runs the usual checks and tells you what to do next.</li>
                    <li>Click a value chip (username, IP, MAC) to drop it into the prompt, or its copy icon to copy it.</li>
                    <li>Commands that take <span class="font-mono">[user]</span> use this connection by default. Paste another username to check that connection instead (same branch only).</li>
                </ol>
                <div class="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs">
                    <div class="mb-1 font-semibold text-slate-700">Colours</div>
                    <div class="grid grid-cols-2 gap-1 font-mono">
                        <span class="rounded bg-slate-950 px-2 py-0.5 text-emerald-400">ok / good</span>
                        <span class="rounded bg-slate-950 px-2 py-0.5 text-amber-300">warning</span>
                        <span class="rounded bg-slate-950 px-2 py-0.5 text-red-400">error / problem</span>
                        <span class="rounded bg-slate-950 px-2 py-0.5 text-slate-500">heading / note</span>
                    </div>
                </div>
            </template>

            <template v-if="section === 'commands'">
                <div v-for="c in filteredCommands" :key="c.name" class="rounded-md border p-2.5" :class="c.danger ? 'border-amber-200 bg-amber-50/40' : 'border-slate-200'">
                    <div class="flex items-center justify-between gap-2">
                        <code class="font-semibold text-slate-800">{{ c.syntax }}</code>
                        <span v-if="c.danger" class="rounded bg-amber-100 px-1.5 text-[10px] font-medium text-amber-800">{{ c.name === 'btest' ? 'loads the line' : 'changes the router' }}</span>
                    </div>
                    <div class="mt-0.5 text-slate-700">{{ c.summary }}</div>
                    <div v-if="c.details" class="mt-1 text-xs text-slate-500">{{ c.details }}</div>
                    <div v-if="c.name === 'btest'" class="mt-1.5 rounded border border-slate-200 bg-white p-2 text-xs">
                        <div class="mb-1 font-semibold text-slate-700">Which IP goes where</div>
                        <div class="flex flex-wrap items-center gap-1.5 font-mono text-[11px]">
                            <span class="rounded bg-slate-100 px-1.5 py-0.5" title="Runs the test (client). Not the address.">{{ ctx.routerName || 'MikroTik' }} {{ ctx.routerIp || '<router>' }}</span>
                            <i class="bi bi-arrow-left-right text-slate-400"></i>
                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-800" title="address= (bandwidth-server)">address={{ ctx.ip || '<ip>' }}</span>
                        </div>
                        <ul class="mt-1 list-disc pl-4 text-slate-600">
                            <li><b>Client</b> = the connection's MikroTik ({{ ctx.routerIp || 'router IP' }}). The command runs here; you never type this IP.</li>
                            <li><b>address=</b> = the customer's {{ ctx.sessionIp ? 'live session IP' : ctx.staticIp ? 'static IP' : 'IP (none now: offline)' }} {{ ctx.ip || '' }}, i.e. their MikroTik CPE with bandwidth-server on. Leave it out and this IP is used.</li>
                            <li>A different server (e.g. a core MikroTik) works too: give its IP, reachable from {{ ctx.routerName || 'the router' }}, with its own user=/password=.</li>
                        </ul>
                    </div>
                    <div v-if="c.output" class="mt-1 text-xs text-slate-500"><b class="text-slate-600">Output:</b> {{ c.output }}</div>
                    <ul v-if="c.tips" class="mt-1 list-disc pl-4 text-xs text-slate-600">
                        <li v-for="t in c.tips" :key="t">{{ fill(t) }}</li>
                    </ul>
                    <div v-if="c.examples" class="mt-1.5 flex flex-wrap gap-1">
                        <span v-for="ex in c.examples.map(fill)" :key="ex" class="inline-flex overflow-hidden rounded border font-mono text-[11px]" :class="needsInput(ex) ? 'border-dashed border-amber-400' : 'border-slate-300'">
                            <button type="button" class="px-1.5 py-0.5 hover:bg-slate-100" :title="needsInput(ex) ? 'Put in the prompt, then fill the <…> part' : 'Put in the prompt'" @click="emit('use', ex)">{{ ex }}</button>
                            <button v-if="!c.danger && !needsInput(ex)" type="button" class="border-l border-slate-300 px-1.5 text-emerald-600 hover:bg-emerald-50" title="Run now" @click="emit('run', ex)"><i class="bi bi-play-fill"></i></button>
                        </span>
                    </div>
                </div>
                <div v-if="!filteredCommands.length" class="py-6 text-center text-slate-400">Nothing matches “{{ search }}”.</div>
            </template>

            <template v-if="section === 'playbooks'">
                <div v-for="p in PLAYBOOKS" :key="p.title" class="rounded-md border border-slate-200 p-2.5">
                    <div class="mb-1.5 font-semibold text-slate-800">{{ p.title }}</div>
                    <ol class="space-y-1">
                        <li v-for="([cmd, why], i) in p.steps" :key="i" class="flex gap-2 text-xs">
                            <span class="w-4 shrink-0 text-right text-slate-400">{{ i + 1 }}.</span>
                            <button type="button" class="shrink-0 rounded border px-1.5 font-mono hover:border-brand-400 hover:text-brand-600" :class="needsInput(fill(cmd)) ? 'border-dashed border-amber-400' : 'border-slate-300'" @click="emit('use', fill(cmd))">{{ fill(cmd) }}</button>
                            <span class="text-slate-600">{{ why }}</span>
                        </li>
                    </ol>
                </div>
            </template>

            <template v-if="section === 'sync'">
                <p>A connection has two statuses. <b>Billing status</b> (active, suspended…) is what <i>should</i> be on the router. <b>Router sync</b> is whether the router really matches.</p>
                <p class="text-xs text-slate-500">"Active" with anything other than Synced or Not managed is highlighted with a red ring: the customer may have no internet even though billing says active, or a suspended customer may still be online.</p>
                <table class="w-full text-xs">
                    <tr v-for="[name, text] in SYNC_STATES" :key="name" class="border-b border-slate-100">
                        <td class="py-1.5 pr-2 font-medium text-slate-700">{{ name }}</td>
                        <td class="py-1.5 text-slate-600">{{ text }}</td>
                    </tr>
                </table>
                <p class="text-xs text-slate-500">The status updates after every push (automatic on activate/suspend/package change, or <span class="font-mono">sync</span>) and after every <span class="font-mono">verify</span>. The Connections page can verify a whole page of connections at once.</p>
            </template>

            <template v-if="section === 'keys'">
                <table class="w-full text-xs">
                    <tr class="border-b border-slate-100"><td class="py-1.5 pr-2 font-mono">Enter</td><td>Run the command</td></tr>
                    <tr class="border-b border-slate-100"><td class="py-1.5 pr-2 font-mono">↑ / ↓</td><td>Previous / next command (history is kept in this browser)</td></tr>
                    <tr class="border-b border-slate-100"><td class="py-1.5 pr-2 font-mono">Ctrl+L</td><td>Clear the screen</td></tr>
                    <tr><td class="py-1.5 pr-2 font-mono">Esc</td><td>Close the terminal panel</td></tr>
                </table>
                <div class="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600">
                    <div class="mb-1 font-semibold text-slate-700">Safety & permissions</div>
                    <ul class="list-disc space-y-0.5 pl-4">
                        <li>Using the terminal needs the <b>Connections</b> permission.</li>
                        <li><span class="font-mono">kick</span> and <span class="font-mono">sync</span> change the router: they need <b>Connection Activate / Suspend</b> and are written to the audit log. They are never quick buttons: type them.</li>
                        <li><span class="font-mono">ros</span> is read-only, needs <b>Routers (MikroTik)</b>, and is audited.</li>
                        <li>Passwords appear only with <b>View PPPoE Password</b>.</li>
                        <li>At most 60 commands per minute per user.</li>
                    </ul>
                </div>
            </template>
        </div>
    </div>
</template>
