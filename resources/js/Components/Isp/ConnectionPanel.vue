<script setup>
import { ref, reactive, watch, computed } from 'vue';
import axios from 'axios';
import Offcanvas from '../Offcanvas.vue';
import SearchSelect from '../SearchSelect.vue';
import StatusBadge from './StatusBadge.vue';
import SyncBadge from './SyncBadge.vue';
import TerminalOffcanvas from './TerminalOffcanvas.vue';
import LiveTrafficChart from './LiveTrafficChart.vue';
import TestDial from './TestDial.vue';
import ConnectionPayOffcanvas from './ConnectionPayOffcanvas.vue';
import { money, fmtDate, label, today, promptReason, useApiError, expiryClass, fmtDateTime, fmtMoney } from '../../lib/isp';
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
const pkg = reactive({ open: false, options: [], selected: null, reason: '', quote: null, quoting: false });

const showPay = ref(false);
const moreOpen = ref(false);
const actions = computed(() => {
    const s = conn.value?.status;
    // pay first: no Activate / Reactivate while the line has no paid time
    const unpaid = !!conn.value?.needs_payment;
    return {
        activate: ['pending', 'inactive'].includes(s) && !unpaid,
        suspend: s === 'active',
        reactivate: s === 'suspended' && !unpaid,
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
    Object.assign(pkg, { open: true, options: res.data.filter((p) => p.id !== conn.value.package_id && (!p.reseller_id || p.reseller_id === conn.value.customer?.reseller_id)), selected: null, reason: '', quote: null });
}

// Day-wise preview of what the change costs or gives back.
watch(
    () => pkg.selected?.id,
    async (id) => {
        pkg.quote = null;
        if (!id) return;
        pkg.quoting = true;
        try {
            const res = await axios.post('/isp/connection-package-quote', { id: conn.value.id, package_id: id });
            if (pkg.selected?.id === id) pkg.quote = res.data;
        } catch (err) {
            showError(err);
        } finally {
            pkg.quoting = false;
        }
    },
);

async function savePackage() {
    if (!pkg.selected) return toast.error('Select the new package');
    busy.value = true;
    try {
        const res = await axios.post('/isp/connection-change-package', { id: conn.value.id, package_id: pkg.selected.id, reason: pkg.reason });
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
                <div><span class="text-slate-400">Package</span><br />{{ conn.package?.name }} · {{ fmtMoney(conn.package?.price) }}<span v-if="Number(conn.discount)"> − {{ money(conn.discount) }}</span></div>
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
                <div><span class="text-slate-400">Expire date</span><br /><span :class="expiryClass(conn.expire_at)">{{ fmtDateTime(conn.expire_at) || 'Unpaid' }}</span></div>
            </div>
            <div v-if="online && online.managed" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-2">
                <div>
                    <!-- two separate facts: can we reach the router, and is the customer connected to it -->
                    <div>
                        <i class="bi bi-router"></i> Router {{ online.router }}<span v-if="online.router_host" class="font-mono text-slate-500"> ({{ online.router_host }})</span>:
                        <span v-if="online.error" class="text-red-600">not reachable · {{ online.error }}</span>
                        <span v-else class="text-emerald-700">reachable</span>
                    </div>
                    <div v-if="!online.error">
                        <i class="bi bi-person"></i> Customer session:
                        <span v-if="online.online" class="font-medium text-emerald-700"><i class="bi bi-circle-fill text-[8px]"></i> Online · {{ online.session?.address }} · up {{ online.session?.uptime }}</span>
                        <span v-else class="text-slate-500"><i class="bi bi-circle text-[8px]"></i> Offline<span v-if="online.reason"> · {{ online.reason }}</span></span>
                    </div>
                </div>
            </div>
            <LiveTrafficChart v-if="online && online.managed && !online.error" :connection-id="conn.id" :package-mbps="conn.package ? { down: conn.package.download_mbps, up: conn.package.upload_mbps } : null" />
            <TestDial v-if="conn.connection_type === 'pppoe' && conn.pppoe_username" :username="conn.pppoe_username" :password="secret" :can-reveal="!!can.connectionSecret" :router-name="online?.router || 'the router'" @reveal="reveal" />
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
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <SearchSelect :options="pkg.options" v-model="pkg.selected" label="display_name" placeholder="New package" />
                    <input v-model="pkg.reason" type="text" placeholder="Reason" class="rounded-md border border-slate-300 px-2 py-1.5" />
                </div>

                <div v-if="pkg.quoting" class="mt-2 text-xs text-slate-400">Calculating day-wise adjustment...</div>
                <div v-else-if="pkg.quote" class="mt-2 rounded-md border border-slate-200 bg-white p-3 text-xs">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">{{ pkg.quote.old_package }} → {{ pkg.quote.new_package }}</span>
                        <span class="text-slate-500">{{ money(pkg.quote.old_charge) }} → {{ money(pkg.quote.new_charge) }} / cycle<template v-if="pkg.quote.difference_tax > 0"> (before tax)</template></span>
                    </div>
                    <template v-if="pkg.quote.days_left">
                        <div class="flex justify-between py-0.5 text-slate-600">
                            <span>Paid days left <span class="text-slate-400">(until {{ fmtDateTime(pkg.quote.expire_at) }})</span></span>
                            <strong>{{ pkg.quote.days_left }} day(s)</strong>
                        </div>
                        <div v-for="b in pkg.quote.bills" :key="b.invoice_no" class="flex justify-between py-0.5 text-slate-500">
                            <span>Unused on {{ b.invoice_no }} · {{ money(b.total) }} ÷ {{ b.paid_days }}d × {{ b.days_left }}d</span>
                            <span>{{ money(b.value) }}</span>
                        </div>
                        <div class="flex justify-between py-0.5 text-slate-500">
                            <span>New package for those days · {{ money(pkg.quote.new_charge) }} ÷ {{ pkg.quote.cycle_days }}d × {{ pkg.quote.days_left }}d</span>
                            <span>{{ money(pkg.quote.cost) }}</span>
                        </div>
                        <div class="mt-1 flex justify-between border-t border-slate-200 pt-1.5 text-sm font-semibold" :class="pkg.quote.difference > 0 ? 'text-red-600' : pkg.quote.difference < 0 ? 'text-emerald-600' : 'text-slate-600'">
                            <span>{{ pkg.quote.difference > 0 ? 'Customer pays (added as due)' : pkg.quote.difference < 0 ? 'Credit to customer balance' : 'No difference' }}</span>
                            <span>{{ money(Math.abs(pkg.quote.difference_gross)) }}</span>
                        </div>
                        <p v-if="pkg.quote.difference_tax > 0" class="text-right text-slate-400">{{ money(Math.abs(pkg.quote.difference)) }} + {{ money(pkg.quote.difference_tax) }} tax</p>
                        <p class="mt-1 text-slate-400">The expiry date stays the same. The next renewal bill uses the new price.</p>
                    </template>
                    <p v-else class="text-slate-500">No paid days are running, so nothing to adjust. The new price applies from the next bill.</p>
                    <p v-if="pkg.quote.rebill.length" class="mt-1 text-amber-700">
                        Unpaid bill {{ pkg.quote.rebill.map((b) => b.invoice_no).join(', ') }} will be voided and re-issued at {{ money(pkg.quote.new_charge) }}.
                    </p>
                </div>

                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5" @click="pkg.open = false">Cancel</button>
                    <button type="button" :disabled="busy || pkg.quoting || !pkg.quote" class="rounded-md bg-brand-500 px-3 py-1.5 text-white disabled:opacity-50" @click="savePackage">Change package</button>
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
        <!-- Everyday actions up front; rarely used ones in "More", each with what it does. -->
        <div v-if="conn" class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
            <span v-if="conn.needs_payment && ['pending', 'suspended'].includes(conn.status)" class="mr-auto text-xs text-red-600"><i class="bi bi-lock"></i> Unpaid — pay first to switch it on</span>
            <button v-if="can.payment && !['terminated', 'inactive'].includes(conn.status)" type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700" @click="showPay = true"><i class="bi bi-cash-coin"></i> Pay</button>
            <template v-if="can.connectionAction">
                <button v-if="actions.activate" :disabled="busy" type="button" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white hover:bg-brand-600" @click="act('activate')"><i class="bi bi-power"></i> {{ conn.status === 'inactive' ? 'Turn on again' : 'Activate' }}</button>
                <button v-if="actions.reactivate" :disabled="busy" type="button" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white hover:bg-brand-600" @click="act('reactivate')"><i class="bi bi-play-circle"></i> Reactivate</button>
            </template>
            <button v-if="can.connection && conn.status !== 'terminated'" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" @click="emit('edit', conn)"><i class="bi bi-pen"></i> Edit</button>
            <div v-if="can.connectionAction && conn.status !== 'terminated'" class="relative">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" @click="moreOpen = !moreOpen">More <i class="bi bi-chevron-up text-xs"></i></button>
                <template v-if="moreOpen">
                    <div class="fixed inset-0 z-10" @click="moreOpen = false"></div>
                    <div class="absolute bottom-full right-0 z-20 mb-1 w-72 overflow-hidden rounded-lg border border-slate-200 bg-white text-left shadow-lg">
                        <button v-if="!pkg.open" type="button" class="block w-full px-3 py-2 text-left hover:bg-slate-50" @click="moreOpen = false; openPackage()">
                            <div class="text-sm font-medium text-slate-800"><i class="bi bi-arrow-left-right"></i> Change package</div>
                            <div class="text-xs text-slate-500">New speed/price from the next bill.</div>
                        </button>
                        <button v-if="actions.suspend" :disabled="busy" type="button" class="block w-full border-t border-slate-100 px-3 py-2 text-left hover:bg-amber-50" @click="moreOpen = false; act('suspend')">
                            <div class="text-sm font-medium text-amber-700"><i class="bi bi-pause-circle"></i> Suspend</div>
                            <div class="text-xs text-slate-500">Cut the line for a while (abuse, customer request). Paid time keeps running; Reactivate brings it back.</div>
                        </button>
                        <button v-if="actions.deactivate" :disabled="busy" type="button" class="block w-full border-t border-slate-100 px-3 py-2 text-left hover:bg-slate-50" @click="moreOpen = false; act('deactivate')">
                            <div class="text-sm font-medium text-slate-700"><i class="bi bi-moon"></i> Deactivate</div>
                            <div class="text-xs text-slate-500">Customer stops using it for now (moved, abroad). No renewal bills; keeps the box port; can be turned on again.</div>
                        </button>
                        <button v-if="actions.terminate" :disabled="busy" type="button" class="block w-full border-t border-slate-100 px-3 py-2 text-left hover:bg-red-50" @click="moreOpen = false; act('terminate')">
                            <div class="text-sm font-medium text-red-600"><i class="bi bi-x-octagon"></i> Terminate</div>
                            <div class="text-xs text-slate-500">Close it for good: no more bills, frees the box port. Can't be undone.</div>
                        </button>
                    </div>
                </template>
            </div>
        </div>
        <ConnectionPayOffcanvas v-model="showPay" :connection-id="conn?.id ?? null" :can-credit="!!can.connectionCredit" @saved="load(); emit('changed')" />
        <TerminalOffcanvas v-if="conn" v-model="showTerminal" :connections="[conn]" :connection-id="conn.id" @changed="load(); emit('changed')" />
    </Offcanvas>
</template>
