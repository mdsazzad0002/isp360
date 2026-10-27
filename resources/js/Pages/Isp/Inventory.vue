<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import Offcanvas from '../../Components/Offcanvas.vue';
import Pagination from '../../Components/Pagination.vue';
import { useToast } from '../../lib/toast';
import { useApiError, fmtDateTime, money } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({ categories: Object, types: Object, staff: Array });
const toast = useToast();
const showError = useApiError();

// ---- items ----
const blank = () => ({ id: null, name: '', category: 'onu', unit: 'pcs', serialized: true, sku: '', unit_cost: '', low_stock: '', is_active: true });
const form = reactive(blank());
const items = ref([]);
const load = () => axios.post('/isp/get-inventory').then((r) => (items.value = r.data));
const lowCount = computed(() => items.value.filter((i) => i.low).length);

async function save() {
    try {
        toast.success((await axios.post('/isp/inventory-item', { ...form })).data.message);
        Object.assign(form, blank());
        load();
    } catch (err) {
        showError(err);
    }
}
const qty = (i, n) => `${Number(n).toLocaleString()} ${i.unit === 'm' ? 'm' : ''}`.trim();

// ---- receive / issue / return / faulty / write off / count ----
const ACTIONS = { receive: 'Receive from supplier', issue: 'Issue to technician', return: 'Return to store', faulty: 'Mark faulty', write_off: 'Write off', count: 'Stock count' };
const act = reactive({ open: false, kind: '', item: null, qty: '', serialsText: '', picked: [], options: [], tech_id: '', from: 'store', unit_cost: '', counted: '', note: '' });
const staffName = (id) => props.staff.find((s) => s.id === Number(id))?.name || id;

// the place stock leaves from, for picking serials
const source = computed(() => (act.kind === 'return' ? (act.tech_id ? `tech:${act.tech_id}` : null) : ['faulty', 'write_off'].includes(act.kind) ? act.from : 'store'));

function openAct(kind, item) {
    Object.assign(act, { open: true, kind, item, qty: '', serialsText: '', picked: [], options: [], tech_id: '', from: 'store', unit_cost: item.unit_cost || '', counted: item.store, note: '' });
    loadOptions();
}
async function loadOptions() {
    act.picked = [];
    act.options = [];
    if (!act.item.serialized || act.kind === 'receive' || act.kind === 'count' || !source.value) return;
    const { data } = await axios.post('/isp/get-inventory-serials', { item_id: act.item.id, location: source.value, condition: act.kind === 'write_off' ? '' : 'good', per_page: 200 });
    act.options = data.data.map((u) => u.serial);
}
async function submitAct() {
    const i = act.item;
    try {
        let res;
        if (act.kind === 'receive') {
            res = await axios.post('/isp/inventory-receive', { item_id: i.id, qty: act.qty, serials: act.serialsText, unit_cost: act.unit_cost, note: act.note });
        } else if (act.kind === 'count') {
            res = await axios.post('/isp/inventory-adjust', { item_id: i.id, counted: act.counted, note: act.note });
        } else {
            res = await axios.post('/isp/inventory-move', { item_id: i.id, type: act.kind, tech_id: act.tech_id || null, from: act.from, qty: act.qty, serials: act.picked, note: act.note });
        }
        toast.success(res.data.message);
        act.open = false;
        load();
        loadMoves();
    } catch (err) {
        showError(err);
    }
}

// ---- serial finder and history ----
const find = reactive({ search: '', rows: [] });
async function findSerial() {
    find.rows = (await axios.post('/isp/get-inventory-serials', { search: find.search, per_page: 50 })).data.data;
}
const moves = ref([]);
const movesPage = ref(1);
const movesLast = ref(1);
const movesFilter = reactive({ item_id: '', type: '', tech_id: '' });
function loadMoves() {
    axios.post('/isp/get-inventory-movements', { ...movesFilter, page: movesPage.value }).then((r) => {
        moves.value = r.data.data;
        movesLast.value = r.data.last_page;
    });
}
onMounted(() => {
    load();
    loadMoves();
});
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-1 text-base font-semibold text-slate-800">{{ form.id ? `Edit ${form.name}` : 'Add item' }}</h1>
            <p class="mb-3 text-xs text-slate-500">
                Stock is counted from every movement: received → store → technician → customer. ONUs and routers are tracked by serial; when an ONU is linked to a connection (OLT page or a finished work order) it leaves stock by itself.
            </p>
            <form class="grid grid-cols-2 gap-2 md:grid-cols-6" @submit.prevent="save">
                <input v-model="form.name" required placeholder="Name (e.g. Huawei HG8010H)" class="col-span-2" :class="input" />
                <select v-model="form.category" :class="input" @change="form.serialized = ['onu', 'router', 'switch'].includes(form.category); form.unit = form.category === 'cable' ? 'm' : 'pcs'">
                    <option v-for="(l, k) in categories" :key="k" :value="k">{{ l }}</option>
                </select>
                <select v-model="form.unit" :class="input"><option value="pcs">pieces</option><option value="m">metres</option></select>
                <input v-model="form.unit_cost" type="number" min="0" step="0.01" placeholder="Unit cost" :class="input" />
                <input v-model="form.low_stock" type="number" min="0" placeholder="Warn at (store)" :class="input" />
                <input v-model="form.sku" placeholder="SKU / model (optional)" :class="input" />
                <label class="flex items-center gap-1 text-sm"><input v-model="form.serialized" type="checkbox" :disabled="form.unit === 'm'" /> Track by serial</label>
                <label class="flex items-center gap-1 text-sm"><input v-model="form.is_active" type="checkbox" /> Active</label>
                <div class="col-span-2 flex gap-2 md:col-span-3">
                    <button type="submit" class="flex-1 rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">{{ form.id ? 'Update' : 'Save' }}</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="Object.assign(form, blank())">Reset</button>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <div v-if="lowCount" class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"><i class="bi bi-exclamation-triangle"></i> {{ lowCount }} item(s) at or below their warning level in the store.</div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Item</th>
                        <th class="px-3 py-2 text-end font-medium">Store</th>
                        <th class="px-3 py-2 text-end font-medium">With technicians</th>
                        <th class="px-3 py-2 text-end font-medium">At customers</th>
                        <th class="px-3 py-2 text-end font-medium">Faulty</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in items" :key="i.id" class="border-b border-slate-100" :class="{ 'opacity-50': !i.is_active }">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ i.name }} <span v-if="i.serialized" class="rounded bg-slate-100 px-1 text-[10px] text-slate-500">serial</span></div>
                            <div class="text-xs text-slate-500">{{ categories[i.category] }}<span v-if="i.sku"> · {{ i.sku }}</span><span v-if="i.unit_cost"> · {{ money(i.unit_cost) }} / {{ i.unit === 'm' ? 'm' : 'pc' }}</span></div>
                        </td>
                        <td class="px-3 py-2 text-end font-medium" :class="i.low ? 'text-red-600' : ''">{{ qty(i, i.store) }}</td>
                        <td class="px-3 py-2 text-end">{{ qty(i, i.techs) }}</td>
                        <td class="px-3 py-2 text-end">{{ qty(i, i.installed) }}</td>
                        <td class="px-3 py-2 text-end text-slate-500">{{ i.faulty || '' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap justify-end gap-1">
                                <button type="button" class="rounded bg-brand-500 px-2 py-0.5 text-xs text-white" @click="openAct('receive', i)">Receive</button>
                                <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="openAct('issue', i)">Issue</button>
                                <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="openAct('return', i)">Return</button>
                                <button v-if="i.serialized" type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="openAct('faulty', i)">Faulty</button>
                                <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="openAct('write_off', i)">Write off</button>
                                <button v-if="!i.serialized" type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="openAct('count', i)">Count</button>
                                <i class="bi bi-pen cursor-pointer self-center px-1 text-brand-500" @click="Object.assign(form, blank(), i)"></i>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!items.length"><td colspan="6" class="px-3 py-6 text-center text-slate-400">No items yet: add the ONUs, routers and cable you keep.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <h2 class="mb-2 text-sm font-semibold text-slate-700">Where is this serial?</h2>
                <form class="flex gap-2" @submit.prevent="findSerial">
                    <input v-model="find.search" required placeholder="Serial or MAC" :class="input" />
                    <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Find</button>
                </form>
                <table v-if="find.rows.length" class="mt-2 w-full text-sm">
                    <tr v-for="u in find.rows" :key="u.id" class="border-b border-slate-100">
                        <td class="py-1 font-mono text-xs">{{ u.serial }}</td>
                        <td class="py-1 text-xs">{{ u.item?.name }}</td>
                        <td class="py-1 text-xs">
                            {{ u.place }}<span v-if="u.condition === 'faulty'" class="text-red-600"> (faulty)</span>
                            <a v-if="u.connection?.customer" :href="`/isp/customer/${u.connection.customer.id}`" class="ms-1 text-brand-600 hover:underline">{{ u.connection.customer.name }}</a>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="me-auto text-sm font-semibold text-slate-700">Stock movements</h2>
                    <select v-model="movesFilter.item_id" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="movesPage = 1; loadMoves()"><option value="">All items</option><option v-for="i in items" :key="i.id" :value="i.id">{{ i.name }}</option></select>
                    <select v-model="movesFilter.tech_id" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="movesPage = 1; loadMoves()"><option value="">Any technician</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </div>
                <table class="w-full text-xs">
                    <tr v-for="m in moves" :key="m.id" class="border-b border-slate-100">
                        <td class="py-1 text-slate-500">{{ fmtDateTime(m.created_at) }}</td>
                        <td class="py-1">{{ types[m.type] }}</td>
                        <td class="py-1">{{ m.item?.name }} <span class="font-mono">{{ m.serial?.serial || `× ${m.qty}` }}</span></td>
                        <td class="py-1 text-slate-500">{{ m.from_name }} → {{ m.to_name }}</td>
                    </tr>
                    <tr v-if="!moves.length"><td class="py-3 text-center text-slate-400">No movements yet</td></tr>
                </table>
                <Pagination v-if="movesLast > 1" class="mt-2" :page="movesPage" :total-pages="movesLast" @change="(p) => { movesPage = p; loadMoves(); }" />
            </div>
        </div>

        <Offcanvas :show="act.open" width="sm:w-[460px]" @close="act.open = false">
            <form v-if="act.item" class="flex h-full flex-col" @submit.prevent="submitAct">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-800">{{ ACTIONS[act.kind] }}: {{ act.item.name }}</div>
                <div class="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                    <label v-if="['issue', 'return'].includes(act.kind)" class="block">Technician
                        <select v-model="act.tech_id" required :class="input" @change="loadOptions"><option value="">—</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </label>
                    <label v-if="['faulty', 'write_off'].includes(act.kind)" class="block">From
                        <select v-model="act.from" :class="input" @change="loadOptions">
                            <option value="store">The store</option>
                            <option v-for="s in staff" :key="s.id" :value="`tech:${s.id}`">Technician {{ s.name }}</option>
                        </select>
                    </label>

                    <template v-if="act.kind === 'receive'">
                        <label v-if="act.item.serialized" class="block">Serial numbers <span class="text-xs text-slate-400">(one per line, or scan them in)</span>
                            <textarea v-model="act.serialsText" required rows="8" class="font-mono" :class="input" placeholder="HWTC1A2B3C4D&#10;HWTC1A2B3C4E"></textarea>
                        </label>
                        <label v-else class="block">Quantity ({{ act.item.unit }}) <input v-model="act.qty" required type="number" min="0.01" step="0.01" :class="input" /></label>
                        <label class="block">Unit cost <input v-model="act.unit_cost" type="number" min="0" step="0.01" :class="input" /></label>
                    </template>
                    <label v-else-if="act.kind === 'count'" class="block">Counted in the store now ({{ act.item.unit }})
                        <input v-model="act.counted" required type="number" min="0" step="0.01" :class="input" />
                        <span class="text-xs text-slate-500">The records say {{ act.item.store }}; the difference is recorded as a stock count adjustment.</span>
                    </label>
                    <template v-else>
                        <div v-if="act.item.serialized">
                            <div class="mb-1">Units <span class="text-xs text-slate-400">({{ act.options.length }} at {{ act.kind === 'return' ? staffName(act.tech_id) || 'the technician' : act.from === 'store' ? 'the store' : staffName(act.from.slice(5)) }})</span></div>
                            <div class="max-h-64 space-y-1 overflow-y-auto rounded border border-slate-200 p-2">
                                <label v-for="sn in act.options" :key="sn" class="flex items-center gap-2 font-mono text-xs"><input v-model="act.picked" type="checkbox" :value="sn" /> {{ sn }}</label>
                                <div v-if="!act.options.length" class="text-xs text-slate-400">None there</div>
                            </div>
                        </div>
                        <label v-else class="block">Quantity ({{ act.item.unit }}) <input v-model="act.qty" required type="number" min="0.01" step="0.01" :class="input" /></label>
                    </template>
                    <label class="block">Note <input v-model="act.note" :class="input" placeholder="Invoice no., reason…" /></label>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="act.open = false">Cancel</button>
                    <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Save</button>
                </div>
            </form>
        </Offcanvas>
    </div>
</template>
