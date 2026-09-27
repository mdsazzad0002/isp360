<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import StatusBadge from './StatusBadge.vue';
import { fmtDateTime, useApiError, promptReason } from '../../lib/isp';
import { useToast } from '../../lib/toast';
import { confirmDialog } from '../../lib/confirm';

// Customer profile → Compliance: KYC documents, consents, data export and erasure.
const props = defineProps({ customerId: { type: Number, required: true } });
const emit = defineEmits(['changed']);
const toast = useToast();
const showError = useApiError();
const data = ref(null);
const form = reactive({ type: '', number: '', note: '', file: null });
const fileInput = ref(null);

const load = async () => {
    data.value = (await axios.post('/isp/get-compliance', { customer_id: props.customerId })).data;
    form.type ||= Object.keys(data.value.types)[0];
};
onMounted(load);

async function call(url, payload, reload = true) {
    try {
        toast.success((await axios.post(url, payload)).data.message);
        if (reload) await load();
        return true;
    } catch (err) {
        showError(err);
        return false;
    }
}

async function upload() {
    const body = new FormData();
    body.append('customer_id', props.customerId);
    for (const k of ['type', 'number', 'note']) if (form[k]) body.append(k, form[k]);
    if (form.file) body.append('file', form.file);
    if (await call('/isp/kyc-document', body)) {
        Object.assign(form, { number: '', note: '', file: null });
        if (fileInput.value) fileInput.value.value = '';
    }
}
async function review(doc, status) {
    const note = status === 'rejected' ? await promptReason('Why is it rejected?', { confirmButtonText: 'Reject' }) : '';
    if (note === null) return;
    call('/isp/kyc-review', { id: doc.id, status, note });
}
async function exportData() {
    try {
        const res = await axios.post('/isp/customer-data-export', { id: props.customerId }, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        Object.assign(document.createElement('a'), { href: url, download: `customer-${props.customerId}-data.json` }).click();
        URL.revokeObjectURL(url);
    } catch (err) {
        showError(err);
    }
}
async function erase() {
    if (!(await confirmDialog({ title: 'Erase this customer\'s personal data?', text: 'Name, phone, e-mail, ID, address, logins and KYC files are removed for good. Bills, payments and legally kept session logs stay under a pseudonym.', confirmButtonText: 'Continue' }))) return;
    const reason = await promptReason('Reason (e.g. the customer\'s erasure request and its date)', { confirmButtonText: 'Erase' });
    if (reason && (await call('/isp/customer-erase', { id: props.customerId, reason }))) emit('changed');
}
const input = 'rounded-md border border-slate-300 px-2 py-1 text-sm';
</script>

<template>
    <div v-if="data" class="space-y-4">
        <div v-if="data.erased_at" class="rounded-md border border-slate-300 bg-slate-50 p-3 text-sm text-slate-600">Personal data erased {{ fmtDateTime(data.erased_at) }}.</div>

        <section>
            <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700">
                Identity (KYC)
                <span class="rounded-full px-2 py-0.5 text-xs" :class="data.kyc_verified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ data.kyc_verified ? 'Verified' : 'Not verified' }}</span>
            </h3>
            <form v-if="!data.erased_at" class="mb-2 flex flex-wrap gap-2" @submit.prevent="upload">
                <select v-model="form.type" :class="input"><option v-for="(t, k) in data.types" :key="k" :value="k">{{ t }}</option></select>
                <input v-model="form.number" placeholder="Document number" maxlength="100" :class="input" />
                <input ref="fileInput" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="text-xs" @change="form.file = $event.target.files[0]" />
                <input v-model="form.note" placeholder="Note" maxlength="255" :class="input" />
                <button type="submit" class="rounded-md bg-brand-500 px-3 py-1 text-sm text-white">Add document</button>
            </form>
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="d in data.documents" :key="d.id" class="border-b border-slate-100">
                        <td class="py-1.5 pe-2">{{ data.types[d.type] || d.type }} <span class="font-mono text-xs text-slate-500">{{ d.number }}</span></td>
                        <td class="py-1.5 pe-2"><a v-if="d.has_file" :href="`/isp/kyc-file/${d.id}`" target="_blank" class="text-xs text-brand-600 hover:underline"><i class="bi bi-file-earmark"></i> {{ d.original_name || 'file' }}</a></td>
                        <td class="py-1.5 pe-2"><StatusBadge :status="d.status" /> <span v-if="d.verified_by" class="text-xs text-slate-400">{{ d.verified_by.name }}</span> <span v-if="d.note" class="text-xs text-slate-500">{{ d.note }}</span></td>
                        <td class="py-1.5 text-end">
                            <template v-if="d.status === 'pending'">
                                <button type="button" class="me-1 rounded border border-emerald-300 px-2 py-0.5 text-xs text-emerald-700" @click="review(d, 'verified')">Verify</button>
                                <button type="button" class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-600" @click="review(d, 'rejected')">Reject</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!data.documents.length"><td class="py-3 text-center text-xs text-slate-400">No documents</td></tr>
                </tbody>
            </table>
        </section>

        <section>
            <h3 class="mb-2 text-sm font-semibold text-slate-700">Consents</h3>
            <label class="mb-2 flex items-center gap-2 text-sm">
                <input type="checkbox" :checked="!data.marketing_opt_out" :disabled="!!data.erased_at" @change="call('/isp/customer-marketing', { customer_id: customerId, opt_out: !$event.target.checked })" />
                Marketing SMS allowed
            </label>
            <p v-if="data.pending_legal.length" class="mb-2 text-xs text-amber-700">Not accepted yet: {{ data.pending_legal.map((d) => `${d.title} v${d.version}`).join(', ') }} (asked in the customer portal).</p>
            <table class="w-full text-xs">
                <tbody>
                    <tr v-for="c in data.consents" :key="c.id" class="border-b border-slate-100">
                        <td class="py-1 pe-2">{{ fmtDateTime(c.created_at) }}</td>
                        <td class="py-1 pe-2">{{ c.type }}<span v-if="c.version"> v{{ c.version }}</span></td>
                        <td class="py-1 pe-2">{{ c.granted ? 'accepted / allowed' : 'withdrawn' }}</td>
                        <td class="py-1 pe-2 text-slate-500">{{ c.source }} <span class="font-mono">{{ c.ip_address }}</span></td>
                    </tr>
                    <tr v-if="!data.consents.length"><td class="py-2 text-center text-slate-400">No consents recorded</td></tr>
                </tbody>
            </table>
        </section>

        <section class="flex flex-wrap gap-2 border-t border-slate-200 pt-3">
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="exportData"><i class="bi bi-download"></i> Export customer data (JSON)</button>
            <button v-if="!data.erased_at" type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600" @click="erase"><i class="bi bi-eraser"></i> Erase personal data</button>
        </section>
    </div>
</template>
