<script setup>
import { ref, computed, watch } from 'vue';
import CommandBlock from './CommandBlock.vue';

// Lab test: dial in as this PPPoE customer from a Linux machine (or a second MikroTik) that sits
// on the router's PPPoE port, so the session, IP, speed limit, terminal and live graph are real.
// The password is only filled in after "Show (audited)" in the panel.
const props = defineProps({
    username: { type: String, required: true },
    password: { type: String, default: '' }, // '' = not revealed
    canReveal: { type: Boolean, default: false },
    routerName: { type: String, default: 'the router' },
});
const emit = defineEmits(['reveal']);

const open = ref(false);
let saved = 'eth0';
try {
    saved = localStorage.getItem('isp-testdial-iface') || 'eth0';
} catch {
    /* default */
}
const iface = ref(saved);
watch(iface, (v) => {
    try {
        localStorage.setItem('isp-testdial-iface', v);
    } catch {
        /* preference only */
    }
});

const pass = computed(() => (props.password && props.password !== '(not set)' ? props.password : '<password>'));
const sh = (v) => `'${String(v).replace(/'/g, `'\\''`)}'`; // POSIX single quotes
const ros = (v) => `"${String(v).replace(/[\\"$]/g, '\\$&')}"`; // RouterOS double quotes
const ifaceSafe = computed(() => (/^[A-Za-z0-9._-]{1,15}$/.test(iface.value) ? iface.value : 'eth0'));

const linux = computed(() =>
    [
        `# keep this open: it holds the session (Ctrl+C hangs up; "persist" redials after a kick)`,
        `sudo pppd plugin pppoe.so ${ifaceSafe.value} user ${sh(props.username)} password ${sh(pass.value)} noauth noipdefault nodefaultroute persist nodetach`,
    ].join('\n'),
);
const load = `# second terminal: fills the line so the live graph moves (Ctrl+C stops)\nsudo ping -f -s 1400 -I ppp0 $(ip -4 -o addr show ppp0 | grep -oP 'peer \\K[\\d.]+')`;
const mikrotik = computed(() =>
    [
        `/interface pppoe-client add name=test-${props.username.replace(/[^A-Za-z0-9_-]/g, '')} interface=ether1 user=${ros(props.username)} password=${ros(pass.value)} add-default-route=no disabled=no`,
        `# remove when done:`,
        `/interface pppoe-client remove [find name=test-${props.username.replace(/[^A-Za-z0-9_-]/g, '')}]`,
    ].join('\n'),
);
</script>

<template>
    <div class="rounded-md border border-slate-200">
        <button type="button" class="flex w-full items-center justify-between px-2 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-50" @click="open = !open">
            <span><i class="bi bi-plug"></i> Test dial <span class="font-normal text-slate-500">· connect as this customer to check it end to end (lab)</span></span>
            <i class="bi" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>
        <div v-if="open" class="space-y-2 border-t border-slate-200 p-2 text-xs text-slate-600">
            <p>Run on a machine that is on the same network (layer 2) as the PPPoE port of <b>{{ routerName }}</b>. If it fails with "Timeout waiting for PADO", the router has no PPPoE server on that port: Routers → <b>Setup</b>.</p>
            <div v-if="pass === '<password>'" class="rounded bg-amber-50 px-2 py-1 text-amber-800">
                The password is not filled in.
                <button v-if="canReveal" type="button" class="font-medium underline" @click="emit('reveal')">Show it (audited)</button>
                <span v-else>You need the "View PPPoE Password" permission, or type it in place of &lt;password&gt;.</span>
            </div>
            <label class="flex items-center gap-2">Linux network interface
                <input v-model.trim="iface" class="w-28 rounded border border-slate-300 px-2 py-0.5 font-mono" placeholder="eth0" />
                <span class="text-slate-400">(see <span class="font-mono">ip -br link</span>; virbr0 for a local VM router)</span>
            </label>
            <CommandBlock :code="linux" label="Linux (needs ppp: sudo apt install ppp)" />
            <CommandBlock :code="load" label="Linux, second terminal" />
            <div class="font-medium text-slate-700">Or from a second MikroTik</div>
            <CommandBlock :code="mikrotik" label="Other MikroTik terminal (ether1 = its port towards the PPPoE server)" />
            <p class="text-slate-500">Connected = "local IP address 10.x.x.x" in pppd. This panel then shows Online with that IP, the live graph moves, and the terminal examples use the real IP.</p>
        </div>
    </div>
</template>
