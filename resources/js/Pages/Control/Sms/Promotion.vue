<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { confirmDialog, errorMessageFrom } from '../../../lib/confirm';

defineOptions({ layout: AppLayout });

const toast = useToast();

const customers = ref([]);
const areas = ref([]);
const search = ref('');
const areaId = ref('');
const selectedIds = ref([]);
const message = ref('');
const sending = ref(false);
const gateways = ref([]);
const selectedGatewayId = ref('');

function loadCustomers() {
    axios.post('/get-customer').then((res) => (customers.value = res.data));
}

function loadAreas() {
    axios.post('/get-area').then((res) => (areas.value = res.data));
}

function loadGateways() {
    axios.post('/get-sms-gateway').then((res) => {
        gateways.value = res.data.filter((g) => g.is_active);
        const defaultGateway = gateways.value.find((g) => g.is_default) || gateways.value[0];
        if (defaultGateway) {
            selectedGatewayId.value = defaultGateway.id;
        }
    });
}

const filteredCustomers = computed(() => {
    return customers.value.filter((c) => {
        if (areaId.value && c.area_id != areaId.value) return false;
        if (search.value) {
            const s = search.value.toLowerCase();
            return (c.name || '').toLowerCase().includes(s) || (c.phone || '').includes(s) || (c.code || '').toLowerCase().includes(s);
        }
        return true;
    });
});

const allSelected = computed(() => filteredCustomers.value.length > 0 && filteredCustomers.value.every((c) => selectedIds.value.includes(c.id)));

function toggleSelectAll() {
    if (allSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !filteredCustomers.value.some((c) => c.id === id));
    } else {
        const ids = new Set(selectedIds.value);
        filteredCustomers.value.forEach((c) => ids.add(c.id));
        selectedIds.value = Array.from(ids);
    }
}

function toggleOne(id) {
    const idx = selectedIds.value.indexOf(id);
    if (idx === -1) selectedIds.value.push(id);
    else selectedIds.value.splice(idx, 1);
}

const messageLength = computed(() => message.value.length);

async function sendPromotion() {
    if (selectedIds.value.length === 0) {
        toast.error('Select at least one customer');
        return;
    }
    if (!message.value.trim()) {
        toast.error('Message is required');
        return;
    }
    const confirmed = await confirmDialog({
        title: 'Send Promotion SMS',
        text: `Send SMS to ${selectedIds.value.length} customer(s)?`,
        icon: 'question',
        confirmButtonText: 'Send',
    });
    if (!confirmed) return;

    sending.value = true;
    try {
        const res = await axios.post('/send-sms-promotion', {
            customerIds: selectedIds.value,
            message: message.value,
            gatewayIds: selectedGatewayId.value ? [selectedGatewayId.value] : [],
        });
        if (res.data.status) {
            toast.success(res.data.message);
            selectedIds.value = [];
        } else {
            toast.error(res.data.message || 'Something went wrong');
        }
    } catch (err) {
        toast.error(errorMessageFrom(err, 'Failed to send SMS'));
    } finally {
        sending.value = false;
    }
}

onMounted(() => {
    loadCustomers();
    loadAreas();
    loadGateways();
});
</script>

<template>
    <div class="mx-auto p-4">
        <div class="mb-3 flex items-center gap-2">
            <i class="bi bi-megaphone text-xl text-brand-500"></i>
            <h1 class="text-lg font-semibold text-slate-800">Send Promotion SMS</h1>
        </div>

        <div v-if="gateways.length === 0" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No active SMS gateway is configured. Add one from
            <a href="/sms-gateway" class="underline">SMS Gateway Setting</a>.
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <div class="flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
                        <input type="text" v-model="search" placeholder="Name / phone / code" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Area</label>
                        <select v-model="areaId" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="">All Areas</option>
                            <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="px-2 py-2">
                                    <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" />
                                </th>
                                <th class="px-2 py-2 font-medium">Code</th>
                                <th class="px-2 py-2 font-medium">Name</th>
                                <th class="px-2 py-2 font-medium">Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in filteredCustomers" :key="c.id" class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-2 py-1.5">
                                    <input type="checkbox" :checked="selectedIds.includes(c.id)" @change="toggleOne(c.id)" />
                                </td>
                                <td class="px-2 py-1.5">{{ c.code }}</td>
                                <td class="px-2 py-1.5">{{ c.name }}</td>
                                <td class="px-2 py-1.5">{{ c.phone }}</td>
                            </tr>
                            <tr v-if="filteredCustomers.length === 0">
                                <td colspan="4" class="px-2 py-6 text-center text-slate-400">No customers found</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="mb-2 text-sm font-medium text-slate-700">{{ selectedIds.length }} customer(s) selected</p>
                <label class="mb-1 block text-xs font-medium text-slate-600">Message</label>
                <textarea v-model="message" rows="6" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"></textarea>
                <p class="mt-1 text-xs text-slate-400">{{ messageLength }} characters</p>

                <div v-if="gateways.length" class="mt-3">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Provider</label>
                    <select v-model="selectedGatewayId" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option v-for="g in gateways" :key="g.id" :value="g.id">{{ g.name }}{{ g.is_default ? ' (Default)' : '' }}</option>
                    </select>
                </div>

                <button
                    type="button"
                    @click="sendPromotion"
                    :disabled="sending || gateways.length === 0"
                    class="mt-4 w-full rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                >
                    {{ sending ? 'Sending...' : 'Send SMS' }}
                </button>
            </div>
        </div>
    </div>
</template>
