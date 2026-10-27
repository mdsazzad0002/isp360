<script setup>
import { ref, reactive, watch, computed, nextTick } from 'vue';
import axios from 'axios';
import Offcanvas from '../Offcanvas.vue';
import { useToast } from '../../lib/toast';
import { useApiError, fmtDateTime, label, promptReason } from '../../lib/isp';

// One work order: what to do, where, and finishing it (result, photos, materials from the technician's
// stock, equipment brought back, ONU serial, customer's signature). Used by Work Orders and My Jobs.
const props = defineProps({ id: { type: [Number, null], default: null }, office: Boolean });
const emit = defineEmits(['close', 'changed']);
const toast = useToast();
const showError = useApiError();

const wo = ref(null);
const busy = ref(false);
const held = ref([]); // what the technician carries
const finish = reactive({ open: false, result: '', onu_serial: '', materials: [], recovered: [] });

const TYPES = { installation: 'New installation', shifting: 'Shifting', repair: 'Repair', disconnection: 'Disconnection', pickup: 'Equipment pickup', other: 'Other' };
const isOpen = computed(() => wo.value && ['new', 'assigned', 'in_progress'].includes(wo.value.status));
const onuSerials = computed(() => held.value.filter((h) => h.item.category === 'onu').flatMap((h) => h.serials));

async function load() {
    if (!props.id) return;
    wo.value = null;
    finish.open = false;
    try {
        wo.value = (await axios.post('/isp/get-work-order', { id: props.id })).data;
    } catch (err) {
        showError(err);
        emit('close');
    }
}
watch(() => props.id, load, { immediate: true });

async function loadHeld() {
    const userId = wo.value.assigned_to;
    try {
        held.value = (await axios.post('/isp/get-inventory-held', props.office && userId ? { user_id: userId } : {})).data;
    } catch {
        held.value = []; // the office without inventory rights still finishes, without materials
    }
}
async function openFinish() {
    Object.assign(finish, { open: true, result: '', onu_serial: '', materials: [], recovered: [] });
    await loadHeld();
    await nextTick();
    setupPad();
}
const addMaterial = () => finish.materials.push({ item_id: '', qty: 1, serials: [] });
const addRecovered = (u) => finish.recovered.push({ item_id: u.item_id, serials: [u.serial], label: `${u.item?.name} ${u.serial}` });
const heldItem = (id) => held.value.find((h) => h.item.id === Number(id));

async function call(url, payload, done) {
    busy.value = true;
    try {
        toast.success((await axios.post(url, payload)).data.message);
        emit('changed');
        await load();
        done?.();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}
const start = () => call('/isp/work-order-start', { id: wo.value.id });
async function cancel() {
    const reason = await promptReason('Why is this job cancelled?');
    if (reason) call('/isp/work-order-cancel', { id: wo.value.id, reason });
}
function submitFinish() {
    const materials = finish.materials.filter((m) => m.item_id).map((m) => ({ item_id: m.item_id, qty: heldItem(m.item_id)?.item.serialized ? 0 : m.qty, serials: m.serials }));
    call('/isp/work-order-complete', {
        id: wo.value.id, result: finish.result, onu_serial: finish.onu_serial, materials,
        recovered: finish.recovered.map(({ item_id, serials }) => ({ item_id, serials })),
        signature: signed.value ? pad.value.toDataURL('image/png') : null,
    }, () => (finish.open = false));
}

async function upload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('id', wo.value.id);
    data.append('photo', file);
    busy.value = true;
    try {
        toast.success((await axios.post('/isp/work-order-photo', data)).data.message);
        await load();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
        e.target.value = '';
    }
}

// customer's signature, drawn with a finger
const pad = ref(null);
const signed = ref(false);
let drawing = false;
function setupPad() {
    const c = pad.value;
    if (!c) return;
    c.width = c.clientWidth;
    c.height = 140;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';
    signed.value = false;
}
function point(e) {
    const r = pad.value.getBoundingClientRect();
    return [e.clientX - r.left, e.clientY - r.top];
}
function down(e) {
    drawing = true;
    const ctx = pad.value.getContext('2d');
    ctx.beginPath();
    ctx.moveTo(...point(e));
    pad.value.setPointerCapture(e.pointerId);
}
function move(e) {
    if (!drawing) return;
    const ctx = pad.value.getContext('2d');
    ctx.lineTo(...point(e));
    ctx.stroke();
    signed.value = true;
}
const up = () => (drawing = false);

const mapUrl = (a) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(a)}`;
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <Offcanvas :show="!!id" width="sm:w-[560px]" @close="emit('close')">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div class="font-semibold text-slate-800">{{ wo ? `${wo.wo_no} · ${TYPES[wo.type]}` : 'Loading…' }}</div>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>
            <div v-if="wo" class="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded bg-slate-100 px-2 py-0.5">{{ label(wo.status) }}</span>
                    <span v-if="wo.priority !== 'normal'" class="rounded bg-red-50 px-2 py-0.5 text-red-700">{{ label(wo.priority) }}</span>
                    <span v-if="wo.scheduled_at" class="rounded bg-slate-100 px-2 py-0.5"><i class="bi bi-calendar"></i> {{ fmtDateTime(wo.scheduled_at) }}</span>
                    <span v-if="wo.assignee" class="rounded bg-slate-100 px-2 py-0.5"><i class="bi bi-person"></i> {{ wo.assignee.name }}</span>
                    <a v-if="wo.ticket" :href="`/isp/tickets?id=${wo.ticket.id}`" class="rounded bg-slate-100 px-2 py-0.5 text-brand-600">{{ wo.ticket.ticket_no }}</a>
                </div>

                <div class="rounded-md border border-slate-200 p-3">
                    <div class="font-medium">{{ wo.customer?.name }} <span class="text-xs text-slate-400">{{ wo.customer?.code }}</span></div>
                    <a v-if="wo.customer?.phone" :href="`tel:${wo.customer.phone}`" class="mt-1 inline-flex items-center gap-1 text-brand-600"><i class="bi bi-telephone"></i> {{ wo.customer.phone }}</a>
                    <div v-if="wo.address" class="mt-1"><a :href="mapUrl(wo.address)" target="_blank" rel="noopener" class="text-brand-600"><i class="bi bi-geo-alt"></i> {{ wo.address }}</a></div>
                    <div v-if="wo.connection" class="mt-1 text-xs text-slate-500">Connection {{ wo.connection.code }}<span v-if="wo.connection.pppoe_username"> · PPPoE {{ wo.connection.pppoe_username }}</span></div>
                </div>
                <div v-if="wo.notes"><div class="text-xs text-slate-400">What to do</div><div class="whitespace-pre-line">{{ wo.notes }}</div></div>
                <div v-if="wo.result"><div class="text-xs text-slate-400">{{ wo.status === 'cancelled' ? 'Cancelled' : 'Done' }}</div><div class="whitespace-pre-line">{{ wo.result }}</div></div>
                <div v-if="wo.onu_serial" class="text-xs">ONU <span class="font-mono">{{ wo.onu_serial }}</span></div>
                <div v-if="wo.materials?.length">
                    <div class="text-xs text-slate-400">Materials</div>
                    <div v-for="m in wo.materials" :key="m.id" class="text-xs">{{ m.type === 'recover' ? 'Brought back' : 'Used' }}: {{ m.item?.name }} {{ m.serial?.serial || `× ${m.qty}` }}</div>
                </div>
                <div v-if="wo.photos?.length || isOpen">
                    <div class="mb-1 text-xs text-slate-400">Photos</div>
                    <div class="flex flex-wrap gap-2">
                        <a v-for="p in wo.photos" :key="p.id" :href="`/isp/work-order-file/${p.id}`" target="_blank"><img :src="`/isp/work-order-file/${p.id}`" class="h-20 w-20 rounded object-cover" alt="" /></a>
                        <label v-if="isOpen" class="flex h-20 w-20 cursor-pointer items-center justify-center rounded border border-dashed border-slate-300 text-slate-400">
                            <i class="bi bi-camera text-xl"></i>
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="upload" />
                        </label>
                    </div>
                </div>
                <div v-if="wo.has_signature"><div class="text-xs text-slate-400">Customer's signature</div><img :src="`/isp/work-order-file/${wo.id}?type=signature`" class="h-24 rounded border border-slate-200" alt="" /></div>

                <!-- finishing the job -->
                <form v-if="finish.open" class="space-y-3 rounded-md border border-brand-200 bg-brand-50/30 p-3" @submit.prevent="submitFinish">
                    <label class="block">What was done <textarea v-model="finish.result" required rows="3" :class="input"></textarea></label>
                    <label v-if="wo.connection" class="block">ONU serial
                        <input v-model="finish.onu_serial" list="wo-onu-serials" placeholder="Scan or type" class="font-mono" :class="input" />
                        <datalist id="wo-onu-serials"><option v-for="s in onuSerials" :key="s" :value="s" /></datalist>
                        <span class="text-xs text-slate-500">From your stock it goes to the customer; on the OLT list it gets linked.</span>
                    </label>
                    <div>
                        <div class="mb-1 flex items-center justify-between"><span>Materials used</span><button type="button" class="text-xs text-brand-600" @click="addMaterial"><i class="bi bi-plus"></i> Add</button></div>
                        <div v-for="(m, i) in finish.materials" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
                            <select v-model="m.item_id" class="flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm" @change="m.serials = []">
                                <option value="">— item —</option>
                                <option v-for="h in held" :key="h.item.id" :value="h.item.id">{{ h.item.name }} ({{ h.qty }} {{ h.item.unit }})</option>
                            </select>
                            <select v-if="heldItem(m.item_id)?.item.serialized" v-model="m.serials" multiple class="w-40 rounded-md border border-slate-300 px-2 py-1 font-mono text-xs">
                                <option v-for="s in heldItem(m.item_id).serials" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <input v-else v-model="m.qty" type="number" min="0.01" step="0.01" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-sm" />
                            <button type="button" class="text-slate-400" @click="finish.materials.splice(i, 1)"><i class="bi bi-x"></i></button>
                        </div>
                        <div v-if="!held.length" class="text-xs text-slate-400">No stock issued to {{ office ? 'this technician' : 'you' }}.</div>
                    </div>
                    <div v-if="wo.installed?.length && ['disconnection', 'pickup', 'shifting', 'repair'].includes(wo.type)">
                        <div class="mb-1">Brought back</div>
                        <div class="flex flex-wrap gap-1">
                            <button v-for="u in wo.installed" :key="u.id" type="button" class="rounded border px-2 py-0.5 font-mono text-xs"
                                :class="finish.recovered.some((r) => r.serials[0] === u.serial) ? 'border-brand-500 bg-brand-500 text-white' : 'border-slate-300'"
                                @click="finish.recovered.some((r) => r.serials[0] === u.serial) ? (finish.recovered = finish.recovered.filter((r) => r.serials[0] !== u.serial)) : addRecovered(u)">
                                {{ u.item?.name }} {{ u.serial }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 flex items-center justify-between"><span>Customer's signature</span><button type="button" class="text-xs text-slate-500" @click="setupPad">Clear</button></div>
                        <canvas ref="pad" class="w-full touch-none rounded border border-slate-300 bg-white" @pointerdown="down" @pointermove="move" @pointerup="up" @pointerleave="up"></canvas>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5" @click="finish.open = false">Back</button>
                        <button type="submit" :disabled="busy" class="rounded-md bg-emerald-600 px-3 py-1.5 text-white">{{ busy ? 'Saving…' : 'Job done' }}</button>
                    </div>
                </form>
            </div>
            <div v-if="wo && isOpen && !finish.open" class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3">
                <button v-if="office" type="button" :disabled="busy" class="me-auto rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600" @click="cancel">Cancel job</button>
                <button v-if="wo.status !== 'in_progress'" type="button" :disabled="busy" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="start"><i class="bi bi-play-fill"></i> Start</button>
                <button type="button" :disabled="busy" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white" @click="openFinish"><i class="bi bi-check2"></i> Finish</button>
            </div>
        </div>
    </Offcanvas>
</template>
