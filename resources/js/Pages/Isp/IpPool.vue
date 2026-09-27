<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';
import { confirmDialog } from '../../lib/confirm';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();
const TYPES = { static: 'Static IPv4', ipv6_pd: 'IPv6 prefix delegation', cgnat: 'CGNAT (deterministic)' };
const blank = () => ({ id: null, name: '', type: 'static', network: '', gateway: '', delegated_length: 56, public_network: '', ports_per_user: 2016, port_start: 1024, notes: '' });
const form = reactive(blank());
const pools = ref([]);
const lookup = reactive({ ip: '', port: '', result: null });

const load = () => axios.post('/isp/get-ip-pools').then((r) => (pools.value = r.data));
onMounted(load);

async function save() {
    try {
        toast.success((await axios.post('/isp/ip-pool', { ...form })).data.message);
        Object.assign(form, blank());
        load();
    } catch (err) {
        showError(err);
    }
}
async function remove(p) {
    if (!(await confirmDialog({ title: `Delete pool ${p.name}?` }))) return;
    try {
        toast.success((await axios.post('/isp/delete-ip-pool', { id: p.id })).data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
async function next(p) {
    try {
        const { data } = await axios.post('/isp/ip-pool-next', { id: p.id });
        toast.success(`Next free in ${p.name}: ${data.value}`);
    } catch (err) {
        showError(err);
    }
}
async function findPrivate() {
    lookup.result = null;
    try {
        lookup.result = (await axios.post('/isp/cgnat-lookup', { ip: lookup.ip, port: lookup.port })).data;
    } catch (err) {
        showError(err);
    }
}
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-1 text-base font-semibold text-slate-800">{{ form.id ? `Edit pool ${form.name}` : 'Add IP pool' }}</h1>
            <p class="mb-3 text-xs text-slate-500">
                <b>Static IPv4</b>: addresses handed to connections one by one (no address on two live lines).
                <b>IPv6 prefix delegation</b>: one prefix per connection (e.g. a /56 of a /40), pushed as Delegated-IPv6-Prefix (RADIUS) or remote-ipv6-prefix (MikroTik).
                <b>CGNAT</b>: every private address always leaves through the same public address and port block, so a regulator's "public IP + port at time t" finds one customer. Download the RouterOS rules for the router.
            </p>
            <form class="grid grid-cols-2 gap-2 md:grid-cols-6" @submit.prevent="save">
                <input v-model="form.name" required placeholder="Name" :class="input" />
                <select v-model="form.type" :class="input"><option v-for="(t, k) in TYPES" :key="k" :value="k">{{ t }}</option></select>
                <input v-model="form.network" required :placeholder="form.type === 'ipv6_pd' ? '2001:db8:100::/40' : form.type === 'cgnat' ? 'Private 100.64.0.0/22' : '10.20.0.0/24'" :class="input" />
                <input v-if="form.type === 'static'" v-model="form.gateway" placeholder="Gateway (skipped)" :class="input" />
                <input v-if="form.type === 'ipv6_pd'" v-model="form.delegated_length" type="number" min="48" max="64" placeholder="Prefix per customer (56)" :class="input" />
                <template v-if="form.type === 'cgnat'">
                    <input v-model="form.public_network" required placeholder="Public 203.0.113.0/28" :class="input" />
                    <input v-model="form.ports_per_user" type="number" min="64" placeholder="Ports per user" :class="input" />
                </template>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">{{ form.id ? 'Update' : 'Save' }}</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="Object.assign(form, blank())">Reset</button>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-3 py-2 font-medium">Pool</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Network</th>
                        <th class="px-3 py-2 text-end font-medium">In use / size</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in pools" :key="p.id" class="border-b border-slate-100">
                        <td class="px-3 py-2 font-medium">{{ p.name }}</td>
                        <td class="px-3 py-2">{{ TYPES[p.type] }}</td>
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ p.network }}<span v-if="p.type === 'ipv6_pd'"> → /{{ p.delegated_length }} each</span>
                            <span v-if="p.type === 'cgnat'"> → {{ p.public_network }}, {{ p.ports_per_user }} ports ({{ p.users_per_ip }} users / public IP)</span>
                        </td>
                        <td class="px-3 py-2 text-end">{{ p.used }} / {{ p.size }}</td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2">
                                <button v-if="p.type !== 'cgnat'" type="button" class="rounded border border-slate-300 px-2 py-0.5 text-xs" @click="next(p)">Next free</button>
                                <a v-else :href="`/isp/cgnat-script/${p.id}`" class="rounded border border-slate-300 px-2 py-0.5 text-xs"><i class="bi bi-download"></i> RouterOS rules</a>
                                <i class="bi bi-pen cursor-pointer self-center text-brand-500" @click="Object.assign(form, blank(), p)"></i>
                                <i class="bi bi-trash cursor-pointer self-center text-red-500" @click="remove(p)"></i>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!pools.length"><td colspan="5" class="px-3 py-6 text-center text-slate-400">No pools yet</td></tr>
                </tbody>
            </table>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">CGNAT lookup <span class="font-normal text-slate-400">— public IP + port to private IP</span></h2>
            <form class="flex flex-wrap gap-2" @submit.prevent="findPrivate">
                <input v-model="lookup.ip" required placeholder="Public IP" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                <input v-model="lookup.port" required type="number" min="1" max="65535" placeholder="Port" class="w-28 rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                <button type="submit" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white">Find</button>
            </form>
            <p v-if="lookup.result" class="mt-2 text-sm">
                Private <b class="font-mono">{{ lookup.result.private_ip }}</b> ({{ lookup.result.pool }}, ports {{ lookup.result.nat_port_start }}–{{ lookup.result.nat_port_end }}).
                <a :href="`/isp/session-log`" class="text-brand-600 hover:underline">Search the session log</a> for that IP and time to get the customer.
            </p>
        </div>
    </div>
</template>
