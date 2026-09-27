<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Offcanvas from '../../Components/Offcanvas.vue';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';
import { fmtDateTime, useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const blocks = ref([]);
const routers = ref([]);
const packages = ref([]);
const busy = ref(false);
const search = ref('');
const typeFilter = ref('all');
const checked = ref([]);
const panel = reactive({ show: false, values: '', scope: 'all', package_id: '', note: '' });

const quickSites = ['facebook.com', 'youtube.com', 'tiktok.com', 'bet365.com', '1xbet.com', 'pornhub.com'];

const filtered = computed(() => {
    const t = search.value.trim().toLowerCase();
    return blocks.value.filter((b) => {
        if (typeFilter.value === 'domain' && b.type !== 'domain') return false;
        if (typeFilter.value === 'ip' && b.type !== 'ip') return false;
        if (typeFilter.value === 'off' && b.is_active) return false;
        return !t || `${b.value} ${b.note || ''} ${b.package?.name || ''}`.toLowerCase().includes(t);
    });
});
const stats = computed(() => ({
    active: blocks.value.filter((b) => b.is_active).length,
    domains: blocks.value.filter((b) => b.type === 'domain').length,
    ips: blocks.value.filter((b) => b.type === 'ip').length,
}));
const lineCount = computed(() => panel.values.split(/[\r\n,]+/).filter((l) => l.trim()).length);
const allChecked = computed(() => filtered.value.length > 0 && filtered.value.every((b) => checked.value.includes(b.id)));

function load() {
    axios.post('/isp/get-blocks').then((r) => {
        blocks.value = r.data.blocks;
        routers.value = r.data.routers;
        checked.value = checked.value.filter((id) => r.data.blocks.some((b) => b.id === id));
    });
}

async function call(url, data) {
    busy.value = true;
    try {
        const res = await axios.post(url, data);
        const failed = (res.data.sync || []).some((s) => s.status === 'failed');
        failed ? toast.error(res.data.message) : toast.success(res.data.message);
        return true;
    } catch (err) {
        showError(err);
        return false;
    } finally {
        busy.value = false;
        load();
    }
}

async function openPanel() {
    Object.assign(panel, { show: true, values: '', scope: 'all', package_id: '', note: '' });
    if (!packages.value.length) {
        const res = await axios.post('/isp/get-packages', { owner: 'company' });
        packages.value = res.data;
    }
}

function addQuick(site) {
    const lines = panel.values.split('\n').map((l) => l.trim()).filter(Boolean);
    if (!lines.includes(site)) panel.values = [...lines, site].join('\n');
}

async function save() {
    if (await call('/isp/block', { values: panel.values, scope: panel.scope, package_id: panel.package_id || null, note: panel.note })) panel.show = false;
}

async function toggle(b) {
    await call('/isp/block-toggle', { id: b.id });
}

async function remove(ids) {
    if (!(await confirmDialog({ title: ids.length > 1 ? `Unblock and remove ${ids.length} entries?` : 'Unblock and remove this entry?' }))) return;
    if (await call('/isp/delete-block', { ids })) checked.value = [];
}

function toggleAll() {
    checked.value = allChecked.value ? [] : filtered.value.map((b) => b.id);
}

onMounted(load);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">Site / IP Block</h1>
                <p class="text-xs text-slate-500">Websites and IP addresses blocked for customers on the MikroTik routers.</p>
            </div>
            <div class="flex gap-2">
                <button type="button" :disabled="busy" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50" @click="call('/isp/block-sync', {})">
                    <i class="bi bi-arrow-repeat" :class="busy && 'animate-spin'"></i> Sync routers
                </button>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-600" @click="openPanel">
                    <i class="bi bi-shield-plus"></i> Block site / IP
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div class="grid grid-cols-3 gap-3 md:col-span-2">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Active blocks</div>
                    <div class="mt-1 text-xl font-semibold text-red-600">{{ stats.active }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">Websites</div>
                    <div class="mt-1 text-xl font-semibold text-slate-800">{{ stats.domains }}</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-xs text-slate-500">IP / subnet</div>
                    <div class="mt-1 text-xl font-semibold text-slate-800">{{ stats.ips }}</div>
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm md:col-span-2">
                <div class="mb-1 text-xs text-slate-500">Router status</div>
                <div v-if="!routers.length" class="text-sm text-amber-700">No active router — add one under Routers (MikroTik).</div>
                <div v-for="r in routers" :key="r.id" class="flex items-start justify-between gap-2 py-0.5 text-sm">
                    <span class="text-slate-700"><i class="bi bi-router"></i> {{ r.name }} <span class="text-xs text-slate-400">{{ r.host }}</span></span>
                    <span class="text-right text-xs">
                        <span v-if="r.block_sync_status === 'synced'" class="text-emerald-600"><i class="bi bi-check-circle"></i> Synced</span>
                        <span v-else-if="r.block_sync_status === 'warning'" class="text-amber-600" :title="r.block_sync_note"><i class="bi bi-exclamation-triangle"></i> Synced with warnings</span>
                        <span v-else-if="r.block_sync_status === 'failed'" class="text-red-600" :title="r.block_sync_note"><i class="bi bi-x-circle"></i> Failed</span>
                        <span v-else class="text-slate-400">Not synced yet</span>
                        <span v-if="r.block_synced_at" class="block text-slate-400">{{ fmtDateTime(r.block_synced_at) }}</span>
                    </span>
                </div>
                <p v-for="r in routers.filter((x) => x.block_sync_note)" :key="'n' + r.id" class="mt-1 text-xs" :class="r.block_sync_status === 'failed' ? 'text-red-600' : 'text-amber-700'">{{ r.name }}: {{ r.block_sync_note }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-3">
                <div class="flex items-center gap-2">
                    <div class="flex gap-1 rounded-md bg-slate-100 p-0.5 text-xs">
                        <button
                            v-for="f in [['all', 'All'], ['domain', 'Websites'], ['ip', 'IP / subnet'], ['off', 'Disabled']]"
                            :key="f[0]"
                            type="button"
                            class="rounded px-3 py-1"
                            :class="typeFilter === f[0] ? 'bg-white font-medium text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            @click="typeFilter = f[0]"
                        >
                            {{ f[1] }}
                        </button>
                    </div>
                    <button v-if="checked.length" type="button" :disabled="busy" class="rounded-md bg-red-50 px-3 py-1 text-xs text-red-600 hover:bg-red-100" @click="remove(checked)">
                        <i class="bi bi-trash"></i> Remove {{ checked.length }}
                    </button>
                </div>
                <div class="relative">
                    <i class="bi bi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input v-model="search" placeholder="Search site, IP, note..." class="w-64 rounded-md border border-slate-300 py-1.5 pl-8 pr-3 text-sm" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="w-8 px-3 py-2"><input type="checkbox" :checked="allChecked" @change="toggleAll" /></th>
                            <th class="px-3 py-2 font-medium">Site / IP</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Blocked for</th>
                            <th class="px-3 py-2 font-medium">Note</th>
                            <th class="px-3 py-2 font-medium">Added</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in filtered" :key="b.id" class="border-b border-slate-100 hover:bg-slate-50" :class="!b.is_active && 'opacity-60'">
                            <td class="px-3 py-2"><input v-model="checked" type="checkbox" :value="b.id" /></td>
                            <td class="px-3 py-2 font-medium text-slate-800">
                                <i class="bi mr-1" :class="b.type === 'domain' ? 'bi-globe2 text-sky-500' : 'bi-hdd-network text-violet-500'"></i>{{ b.value }}
                            </td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="b.type === 'domain' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'">{{ b.type === 'domain' ? 'Website' : b.value.includes('/') ? 'Subnet' : 'IP' }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                <span v-if="b.scope === 'all'">All customers</span>
                                <span v-else><i class="bi bi-speedometer2 text-slate-400"></i> {{ b.package?.name || 'Deleted package' }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-500">{{ b.note }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ fmtDateTime(b.created_at) }}<span v-if="b.created_by" class="block">{{ b.created_by.name }}</span></td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs" :class="b.is_active ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="b.is_active ? 'bg-red-500' : 'bg-slate-400'"></span>
                                    {{ b.is_active ? 'Blocked' : 'Disabled' }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1">
                                    <button type="button" :disabled="busy" class="rounded p-1.5 hover:bg-slate-100" :class="b.is_active ? 'text-amber-600' : 'text-emerald-600'" :title="b.is_active ? 'Unblock (keep in list)' : 'Block again'" @click="toggle(b)">
                                        <i class="bi" :class="b.is_active ? 'bi-pause-circle' : 'bi-play-circle'"></i>
                                    </button>
                                    <button type="button" :disabled="busy" class="rounded p-1.5 text-red-500 hover:bg-red-50" title="Remove" @click="remove([b.id])"><i class="bi bi-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="8" class="px-3 py-10 text-center text-slate-400">
                                <i class="bi bi-shield-check mb-1 block text-2xl"></i>
                                {{ blocks.length ? 'Nothing matches the filter' : 'Nothing is blocked yet' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Offcanvas :show="panel.show" width="sm:w-[520px]" @close="panel.show = false">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-bold text-slate-800"><i class="bi bi-shield-plus"></i> Block site / IP</h2>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="panel.show = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="save">
                <div class="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-5 text-sm">
                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Websites or IPs <span class="text-red-500">*</span> <span class="font-normal text-slate-400">— one per line</span></label>
                        <textarea
                            v-model="panel.values"
                            rows="7"
                            required
                            placeholder="facebook.com&#10;https://www.example.com/page&#10;203.0.113.10&#10;198.51.100.0/24"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm"
                        ></textarea>
                        <div class="mt-1 flex items-center justify-between text-xs text-slate-400">
                            <span>Links are cut down to the domain; subdomains are blocked too.</span>
                            <span>{{ lineCount }} entr{{ lineCount === 1 ? 'y' : 'ies' }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <button v-for="s in quickSites" :key="s" type="button" class="rounded-full border border-slate-200 px-2 py-0.5 text-xs text-slate-600 hover:border-brand-500 hover:text-brand-600" @click="addQuick(s)">+ {{ s }}</button>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Block for</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="cursor-pointer rounded-md border p-2.5" :class="panel.scope === 'all' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
                                <input v-model="panel.scope" type="radio" value="all" class="mr-1" />
                                <span class="font-medium text-slate-700">All customers</span>
                                <span class="mt-0.5 block text-xs text-slate-500">Everyone on the network.</span>
                            </label>
                            <label class="cursor-pointer rounded-md border p-2.5" :class="panel.scope === 'package' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
                                <input v-model="panel.scope" type="radio" value="package" class="mr-1" />
                                <span class="font-medium text-slate-700">One package</span>
                                <span class="mt-0.5 block text-xs text-slate-500">Only customers on it.</span>
                            </label>
                        </div>
                        <select v-if="panel.scope === 'package'" v-model="panel.package_id" required class="mt-2 w-full rounded-md border border-slate-300 px-3 py-1.5">
                            <option value="" disabled>Select package</option>
                            <option v-for="p in packages" :key="p.id" :value="p.id">{{ p.name }} ({{ p.download_mbps }} Mbps)</option>
                        </select>
                        <p v-if="panel.scope === 'package'" class="mt-1 text-xs text-slate-500">Customers already online are matched after they reconnect.</p>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note / reason</label>
                        <input v-model="panel.note" maxlength="255" placeholder="e.g. BTRC order, gambling" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </section>

                    <div class="rounded-md border border-sky-100 bg-sky-50 p-3 text-xs text-sky-800">
                        <i class="bi bi-info-circle"></i> The router drops traffic to the site's IP addresses (kept up to date by the router) and answers its DNS
                        lookups with "not found" when customers use the router as DNS. Sites behind a shared CDN or reached through a VPN may still get through.
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-5 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm" @click="panel.show = false">Cancel</button>
                    <button type="submit" :disabled="busy || !lineCount" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                        {{ busy ? 'Blocking...' : `Block ${lineCount || ''}` }}
                    </button>
                </div>
            </form>
        </Offcanvas>
    </div>
</template>
