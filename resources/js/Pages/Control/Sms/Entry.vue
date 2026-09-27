<script setup>
import { reactive, ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useToast } from '../../../lib/toast';
import { confirmDialog, errorMessageFrom } from '../../../lib/confirm';

defineOptions({ layout: AppLayout });

const toast = useToast();

function emptyForm() {
    return {
        id: '',
        name: '',
        provider_type: 'custom',
        method: 'GET',
        url_template: '',
        api_key: '',
        has_api_key: false,
        sender_id: '',
        sms_type: 'text',
        label: 'promotional',
        is_active: true,
    };
}

const form = reactive(emptyForm());
const rows = ref([]);
const onProgress = ref(false);

function load() {
    axios.post('/get-sms-gateway').then((res) => (rows.value = res.data));
}

function resetForm() {
    Object.assign(form, emptyForm());
    onProgress.value = false;
}

async function saveData() {
    const url = form.id !== '' ? '/update-sms-gateway' : '/sms-gateway';
    onProgress.value = true;
    try {
        const res = await axios.post(url, form);
        toast.success(res.data.message);
        resetForm();
        load();
    } catch (err) {
        onProgress.value = false;
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    }
}

function editRow(row) {
    Object.assign(form, {
        id: row.id,
        name: row.name,
        provider_type: row.provider_type || 'custom',
        method: row.method,
        url_template: row.url_template || '',
        // the saved key is never sent back; blank keeps it
        api_key: '',
        has_api_key: !!row.has_api_key,
        sender_id: row.sender_id || '',
        sms_type: row.sms_type || 'text',
        label: row.label || 'promotional',
        is_active: !!row.is_active,
    });
}

async function deleteRow(id) {
    const confirmed = await confirmDialog({ title: 'Delete SMS Gateway', text: 'Are you sure you want to delete this gateway?' });
    if (!confirmed) return;
    try {
        const res = await axios.post('/delete-sms-gateway', { id });
        if (res.data.status) {
            toast.success(res.data.message);
            load();
        }
    } catch (err) {
        toast.error(errorMessageFrom(err, 'Failed to delete gateway'));
    }
}

async function toggleActive(row) {
    const previous = row.is_active;
    row.is_active = !row.is_active;
    try {
        const res = await axios.post('/toggle-sms-gateway', { id: row.id, is_active: row.is_active });
        if (!res.data.status) {
            row.is_active = previous;
            toast.error(res.data.message || 'Something went wrong');
        }
    } catch (err) {
        row.is_active = previous;
        toast.error(errorMessageFrom(err, 'Failed to update status'));
    }
}

async function makeDefault(row) {
    try {
        const res = await axios.post('/default-sms-gateway', { id: row.id });
        if (res.data.status) {
            toast.success(res.data.message);
            load();
        } else {
            toast.error(res.data.message || 'Something went wrong');
        }
    } catch (err) {
        toast.error(errorMessageFrom(err, 'Failed to set default gateway'));
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto p-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="mb-3 text-base font-semibold text-slate-800">SMS Gateway Setting</h1>
            <p class="mb-3 text-xs text-slate-500">
                Add one or more SMS provider gateways. Multiple gateways can be marked Active at the same time — promotional SMS will be sent through them (falling back to the next one if a
                gateway fails). One gateway must be marked Default. Use <code>{number}</code> and <code>{message}</code> placeholders in the URL Template; any API key / sender id should be
                baked into the URL as your provider requires.
            </p>
            <form @submit.prevent="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="space-y-3 md:col-span-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Gateway Name</label>
                        <input type="text" autocomplete="off" v-model="form.name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Provider</label>
                        <select v-model="form.provider_type" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="custom">Custom URL Template</option>
                            <option value="mram">MRAM (sms.mram.com.bd)</option>
                            <option value="gennet">GenNet (isms.gennet.com.bd)</option>
                            <option value="twilio">Twilio</option>
                            <option value="vonage">Vonage (Nexmo)</option>
                            <option value="infobip">Infobip</option>
                        </select>
                    </div>
                    <div v-if="form.provider_type === 'custom'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                        <select v-model="form.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" v-model="form.is_active" />
                        Active
                    </label>
                </div>
                <div class="space-y-3 md:col-span-6">
                    <template v-if="form.provider_type === 'mram'">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">API Key</label>
                            <input type="text" autocomplete="off" v-model="form.api_key" :placeholder="form.id && form.has_api_key ? 'Saved (hidden). Leave blank to keep it' : 'R700007167f50e7feb3736.24890534'" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Sender ID</label>
                            <input type="text" autocomplete="off" v-model="form.sender_id" placeholder="Approved Sender ID" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="mb-1 block text-xs font-medium text-slate-600">SMS Type</label>
                                <select v-model="form.sms_type" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                    <option value="text">Text (English)</option>
                                    <option value="unicode">Unicode (Bangla)</option>
                                </select>
                            </div>
                            <div class="flex-1">
                                <label class="mb-1 block text-xs font-medium text-slate-600">Label</label>
                                <select v-model="form.label" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                    <option value="promotional">Promotional</option>
                                    <option value="transactional">Transactional</option>
                                </select>
                            </div>
                        </div>
                    </template>
                    <template v-else-if="form.provider_type === 'gennet'">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">API Token</label>
                            <input type="text" autocomplete="off" v-model="form.api_key" :placeholder="form.id && form.has_api_key ? 'Saved (hidden). Leave blank to keep it' : 'API token provided by GenNet'" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">SID</label>
                            <input type="text" autocomplete="off" v-model="form.sender_id" placeholder="e.g. BDSNONMASK" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </template>
                    <template v-else-if="['twilio', 'vonage', 'infobip'].includes(form.provider_type)">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ { twilio: 'Account SID:Auth Token', vonage: 'API key:API secret', infobip: 'API key' }[form.provider_type] }}</label>
                            <input type="text" autocomplete="off" v-model="form.api_key" :placeholder="form.id && form.has_api_key ? 'Saved (hidden). Leave blank to keep it' : form.provider_type === 'infobip' ? 'App key' : 'id:secret'" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">From (number or sender name{{ form.provider_type === 'twilio' ? ', or Messaging Service SID MG…' : '' }})</label>
                            <input type="text" autocomplete="off" v-model="form.sender_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div v-if="form.provider_type === 'infobip'">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Base URL of your Infobip account</label>
                            <input type="url" autocomplete="off" v-model="form.url_template" placeholder="https://xxxxx.api.infobip.com" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <p class="text-xs text-slate-500">Numbers are sent in international form (+country code), converted from the stored ones.</p>
                    </template>
                    <div v-else>
                        <label class="mb-1 block text-xs font-medium text-slate-600">URL Template</label>
                        <textarea
                            v-model="form.url_template"
                            rows="4"
                            placeholder="https://api.provider.com/send?api_key=XXXX&senderid=XXXX&number={number}&message={message}"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono"
                        ></textarea>
                    </div>
                </div>
                <div class="flex items-end gap-2 md:col-span-2">
                    <button type="button" @click="resetForm" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reset</button>
                    <button type="submit" :disabled="onProgress" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                        {{ form.id === '' ? 'Save' : 'Update' }}
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Provider</th>
                            <th class="px-2 py-2 font-medium">Details</th>
                            <th class="px-2 py-2 font-medium">Active</th>
                            <th class="px-2 py-2 font-medium">Default</th>
                            <th class="px-2 py-2 text-end font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5">{{ { mram: 'MRAM', gennet: 'GenNet', twilio: 'Twilio', vonage: 'Vonage', infobip: 'Infobip' }[row.provider_type] || 'Custom (' + row.method + ')' }}</td>
                            <td
                                class="max-w-xs truncate px-2 py-1.5 font-mono text-xs"
                                :title="row.provider_type === 'mram' ? 'Sender: ' + row.sender_id : row.provider_type === 'gennet' ? 'SID: ' + row.sender_id : row.url_template"
                            >
                                {{
                                    row.provider_type === 'mram'
                                        ? 'Sender: ' + row.sender_id + ' (' + row.sms_type + ', ' + row.label + ')'
                                        : row.provider_type === 'gennet'
                                          ? 'SID: ' + row.sender_id
                                          : row.url_template
                                }}
                            </td>
                            <td class="px-2 py-1.5">
                                <label class="relative inline-flex cursor-pointer items-center">
                                    <input type="checkbox" :checked="!!row.is_active" @change="toggleActive(row)" class="peer sr-only" />
                                    <div class="h-5 w-9 rounded-full bg-slate-200 transition peer-checked:bg-brand-500"></div>
                                    <div class="absolute start-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4"></div>
                                </label>
                            </td>
                            <td class="px-2 py-1.5">
                                <span v-if="row.is_default" class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">
                                    <i class="bi bi-star-fill"></i> Default
                                </span>
                                <button v-else type="button" @click="makeDefault(row)" class="text-xs text-brand-600 hover:underline">Make Default</button>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="flex justify-end gap-3">
                                    <i @click="editRow(row)" title="edit" class="bi bi-pen cursor-pointer text-brand-500"></i>
                                    <i @click="deleteRow(row.id)" title="delete" class="bi bi-trash cursor-pointer text-red-500"></i>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="6" class="px-2 py-6 text-center text-slate-400">No SMS gateway configured</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
