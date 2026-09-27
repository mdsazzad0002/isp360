<script setup>
import { ref, reactive, watch, computed } from 'vue';
import axios from 'axios';
import Offcanvas from '../Offcanvas.vue';
import SearchSelect from '../SearchSelect.vue';
import CustomerPicker from './CustomerPicker.vue';
import QuickBoxOffcanvas from './QuickBoxOffcanvas.vue';
import QuickAreaOffcanvas from './QuickAreaOffcanvas.vue';
import { today, useApiError } from '../../lib/isp';
import { useToast } from '../../lib/toast';

const props = defineProps({
    show: Boolean,
    connection: { type: Object, default: null },
    customerId: { type: [Number, null], default: null },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToast();
const showError = useApiError();

const customer = ref(null);
const packages = ref([]);
const boxes = ref([]);
const selectedPackage = ref(null);
const selectedBox = ref(null);
const areas = ref([]);
const selectedArea = ref(null);
const saving = ref(false);
const form = reactive({});
const referrer = ref(null);
const settings = ref({});

function reset() {
    const c = props.connection;
    Object.assign(form, {
        id: c?.id ?? null,
        connection_type: c?.connection_type ?? 'pppoe',
        pppoe_username: c?.pppoe_username ?? '',
        pppoe_password: '',
        static_ip: c?.static_ip ?? '',
        mac_address: c?.mac_address ?? '',
        discount: Number(c?.discount ?? 0),
        installation_date: c?.installation_date ?? today(),
        activation_date: today(),
        activate_now: true,
        charge_installation: true,
        bonus_days: Number(settings.value.init_bonus_days || 0),
        notes: c?.notes ?? '',
        reason: '',
    });
    selectedBox.value = null;
    selectedArea.value = null;
    selectedPackage.value = null;
}

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        referrer.value = null;
        if (!props.connection) {
            settings.value = (await axios.post('/isp/get-settings').catch(() => ({ data: {} }))).data;
        }
        reset();
        const [p] = await Promise.all([axios.post('/isp/get-packages', { activeOnly: true }), loadBoxes(), loadAreas()]);
        packages.value = p.data;
        if (props.connection?.box_id) selectBox(boxes.value.find((x) => x.id === props.connection.box_id));
        else if (customer.value && !form.id) preselectFromCustomer(customer.value);
    }
);

async function loadBoxes() {
    const b = await axios.post('/isp/get-boxes');
    boxes.value = b.data.map((x) => ({ ...x, display_name: `${x.display_name} — ${x.area?.name ?? ''}${x.capacity ? ` (${x.available_ports} free)` : ''}` }));
}

async function loadAreas() {
    const res = await axios.post('/get-area');
    areas.value = res.data.map((x) => ({ ...x, display_name: x.zone?.name ? `${x.name} — ${x.zone.name}` : x.name }));
}

// Boxes of the chosen area only; picking a box also sets its area.
const boxOptions = computed(() => (selectedArea.value && !form.id ? boxes.value.filter((b) => b.area_id === selectedArea.value.id) : boxes.value));
function selectBox(box) {
    selectedBox.value = box || null;
    if (box) selectedArea.value = areas.value.find((a) => a.id === box.area_id) || selectedArea.value;
}
watch(selectedArea, (a) => {
    if (selectedBox.value && a && selectedBox.value.area_id !== a.id) selectedBox.value = null;
});
watch(selectedBox, (b) => {
    if (b && b.area_id !== selectedArea.value?.id) selectedArea.value = areas.value.find((a) => a.id === b.area_id) || selectedArea.value;
});
function preselectFromCustomer(c) {
    if (c.box_id && !selectedBox.value) selectBox(boxes.value.find((x) => x.id === c.box_id));
    if (c.area_id && !selectedArea.value) selectedArea.value = areas.value.find((a) => a.id === c.area_id) || null;
}

// "+" next to Area / Box: create one without leaving the form, then select it.
const showQuickArea = ref(false);
const showQuickBox = ref(false);
async function onAreaCreated(id) {
    await loadAreas();
    selectedArea.value = areas.value.find((a) => a.id === id) || null;
}
async function onBoxCreated(id) {
    await Promise.all([loadBoxes(), loadAreas()]);
    selectBox(boxes.value.find((x) => x.id === id));
}

// A reseller's own packages are only offered for that reseller's customers.
const packageOptions = computed(() => packages.value.filter((p) => !p.reseller_id || p.reseller_id === customer.value?.reseller_id));

watch(customer, (c) => {
    if (selectedPackage.value?.reseller_id && selectedPackage.value.reseller_id !== c?.reseller_id) selectedPackage.value = null;
    if (c && !form.id) preselectFromCustomer(c);
});

async function save() {
    if (!form.id && (!customer.value || !selectedPackage.value)) return toast.error('Customer and package are required');
    if (!form.id && !selectedArea.value) return toast.error('Select the area of this connection');
    saving.value = true;
    try {
        const res = await axios.post('/isp/connection', {
            ...form,
            customer_id: customer.value?.id,
            package_id: selectedPackage.value?.id,
            box_id: selectedBox.value?.id ?? null,
            area_id: form.id ? undefined : selectedArea.value?.id,
            referred_by_id: form.id ? undefined : referrer.value?.id ?? null,
        });
        toast.success(res.data.message);
        emit('saved', res.data.id);
        emit('close');
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Offcanvas :show="show" width="sm:w-[640px]" @close="emit('close')">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-base font-semibold text-slate-800">{{ form.id ? `Edit connection ${connection?.code}` : 'New connection' }}</h2>
            <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 text-sm" @submit.prevent="save">
            <template v-if="!form.id">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
                    <CustomerPicker v-model="customer" :preselect-id="customerId" :disabled="!!customerId" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Package</label>
                    <SearchSelect :options="packageOptions" v-model="selectedPackage" label="display_name" placeholder="Select package" />
                </div>
            </template>
            <div v-else class="rounded-md bg-slate-50 p-2 text-slate-600">Package: <strong>{{ connection?.package?.name }}</strong> — use <em>Change package</em> to switch plans (kept in package history).</div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Connection type</label>
                    <select v-model="form.connection_type" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                        <option value="pppoe">PPPoE</option>
                        <option value="hotspot">Hotspot</option>
                        <option value="static">Static IP</option>
                        <option value="dhcp">DHCP</option>
                    </select>
                </div>
                <div v-if="!form.id">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Area <span class="text-red-500">*</span></label>
                    <div class="flex gap-1">
                        <div class="min-w-0 flex-1"><SearchSelect :options="areas" v-model="selectedArea" label="display_name" :placeholder="areas.length ? 'Select area' : 'No area yet — add one'" /></div>
                        <button type="button" class="shrink-0 rounded-md border border-slate-300 px-2.5 text-brand-600 hover:bg-brand-50" title="Quick add area" @click="showQuickArea = true"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Box</label>
                    <div class="flex gap-1">
                        <div class="min-w-0 flex-1"><SearchSelect :options="boxOptions" v-model="selectedBox" label="display_name" :placeholder="boxOptions.length ? 'Select box' : selectedArea ? 'No box in this area — add one' : 'No box yet — add one'" /></div>
                        <button type="button" class="shrink-0 rounded-md border border-slate-300 px-2.5 text-brand-600 hover:bg-brand-50" title="Quick add box" @click="showQuickBox = true"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div v-if="['pppoe', 'hotspot'].includes(form.connection_type)">
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ form.connection_type === 'hotspot' ? 'Hotspot' : 'PPPoE' }} username</label>
                    <input v-model="form.pppoe_username" type="text" autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div v-if="['pppoe', 'hotspot'].includes(form.connection_type)">
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ form.connection_type === 'hotspot' ? 'Hotspot' : 'PPPoE' }} password</label>
                    <input v-model="form.pppoe_password" type="password" autocomplete="new-password" :placeholder="form.id ? 'Leave blank to keep current' : ''" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Static IP <span v-if="form.connection_type !== 'static'" class="text-slate-400">(optional)</span></label>
                    <input v-model="form.static_ip" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">MAC address <span v-if="form.connection_type === 'hotspot'" class="text-slate-400">(locks login to this device)</span></label>
                    <input v-model="form.mac_address" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Monthly discount (Tk)</label>
                    <input v-model="form.discount" type="number" min="0" step="0.01" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Installation date</label>
                    <input v-model="form.installation_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
            </div>

            <div v-if="!form.id" class="space-y-2 rounded-md border border-slate-200 p-3">
                <label class="flex items-center gap-2"><input v-model="form.activate_now" type="checkbox" /> Activate now (the time starts when the bill is paid)</label>
                <div v-if="form.activate_now" class="w-48">
                    <input v-model="form.activation_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Bonus days <span class="text-slate-400">(free, added to the first paid time)</span></label>
                        <input v-model="form.bonus_days" type="number" min="0" max="365" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>
                    <div v-if="!customer?.referred_by_id">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Referred by <span class="text-slate-400">(existing customer)</span></label>
                        <CustomerPicker v-model="referrer" placeholder="Search referrer (optional)" />
                        <p v-if="settings.referral_enabled && referrer" class="mt-1 text-xs text-emerald-700">
                            {{ referrer.name }} gets {{ settings.referral_commission_type === 'percent' ? `${settings.referral_commission}% of the first bill` : `Tk ${settings.referral_commission}` }} in their wallet once this first bill is paid.
                        </p>
                    </div>
                </div>
                <label v-if="selectedPackage && (Number(selectedPackage.installation_fee) || Number(selectedPackage.activation_fee))" class="flex items-center gap-2">
                    <input v-model="form.charge_installation" type="checkbox" /> Invoice installation/activation fee (Tk {{ Number(selectedPackage.installation_fee) + Number(selectedPackage.activation_fee) }})
                </label>
            </div>
            <div v-else>
                <label class="mb-1 block text-xs font-medium text-slate-600">Reason for change <span class="text-slate-400">(kept in history)</span></label>
                <input v-model="form.reason" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Notes</label>
                <textarea v-model="form.notes" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-1.5"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="emit('close')">Cancel</button>
                <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-white hover:bg-brand-600 disabled:opacity-50">Save</button>
            </div>
        </form>
        <QuickAreaOffcanvas v-model="showQuickArea" :zone-id="customer?.zone_id ?? null" @saved="onAreaCreated" />
        <QuickBoxOffcanvas v-model="showQuickBox" :area-id="selectedArea?.id ?? customer?.area_id ?? null" @saved="onBoxCreated" />
    </Offcanvas>
</template>
