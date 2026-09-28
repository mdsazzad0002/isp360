<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import axios from 'axios';
import StatusBadge from './StatusBadge.vue';
import { useToast } from '../../lib/toast';
import { fmtDate, label, useApiError, PRIORITY_CLASSES } from '../../lib/isp';

// One ticket's conversation + reply box, shared by the admin panel and both portals.
//  base:   endpoint prefix ('/isp', '/reseller', '/customer-portal')
//  viewer: 'admin' | 'reseller' | 'customer' (which side of the thread is "me")
const props = defineProps({
    ticketId: { type: Number, required: true },
    base: { type: String, required: true },
    viewer: { type: String, required: true },
    staff: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed', 'close']);
const toast = useToast();
const showError = useApiError();

const ticket = ref(null);
const replies = ref([]);
const isRequester = ref(false);
const reply = reactive({ message: '', internal: false, status: '', file: null, saving: false });
const fileInput = ref(null);
const threadEl = ref(null);

const isAdmin = computed(() => props.viewer === 'admin');
const closed = computed(() => ticket.value?.status === 'closed');
// Status choices this viewer may set (the server enforces the same rules).
const statusChoices = computed(() => {
    if (!ticket.value) return [];
    if (isAdmin.value) return ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
    if (closed.value) return [];
    return isRequester.value ? ['resolved', 'closed'] : ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
});

async function load() {
    const res = await axios.post(`${props.base}/get-ticket`, { id: props.ticketId });
    ticket.value = res.data.ticket;
    replies.value = res.data.replies;
    isRequester.value = !!res.data.isRequester;
    await nextTick();
    if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight;
}

const mine = (r) => r.author_type === props.viewer;

async function send() {
    reply.saving = true;
    try {
        const fd = new FormData();
        fd.append('id', props.ticketId);
        fd.append('message', reply.message);
        if (reply.file) fd.append('attachment', reply.file);
        if (isAdmin.value && reply.internal) fd.append('internal', '1');
        if (isAdmin.value && reply.status) fd.append('status', reply.status);
        const res = await axios.post(`${props.base}/ticket-reply`, fd);
        toast.success(res.data.message);
        Object.assign(reply, { message: '', internal: false, status: '', file: null });
        if (fileInput.value) fileInput.value.value = '';
        await load();
        emit('changed');
    } catch (err) {
        showError(err);
    } finally {
        reply.saving = false;
    }
}

async function setStatus(status) {
    try {
        const res = await axios.post(`${props.base}/ticket-status`, { id: props.ticketId, status });
        toast.success(res.data.message);
        await load();
        emit('changed');
    } catch (err) {
        showError(err);
    }
}

async function update(field, value) {
    try {
        await axios.post('/isp/ticket-update', { id: props.ticketId, [field]: value });
        toast.success('Ticket updated');
        await load();
        emit('changed');
    } catch (err) {
        showError(err);
    }
}

const isImage = (path) => /\.(jpe?g|png|webp)$/i.test(path || '');
const who = (r) => (r.author_type === 'admin' ? `${r.author_name} (Support)` : r.author_type === 'reseller' ? `${r.author_name} (Reseller)` : r.author_name);

watch(() => props.ticketId, load, { immediate: true });
</script>

<template>
    <div v-if="ticket" class="flex h-full flex-col">
        <div class="border-b border-slate-200 p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-xs text-slate-400">{{ ticket.ticket_no }} · {{ label(ticket.category) }} · opened {{ fmtDate(ticket.created_at) }}</div>
                    <h2 class="text-base font-semibold text-slate-800">{{ ticket.subject }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <StatusBadge :status="ticket.status" />
                        <span :class="PRIORITY_CLASSES[ticket.priority]">{{ label(ticket.priority) }} priority</span>
                        <span v-if="ticket.customer">· Customer: {{ ticket.customer.name }} ({{ ticket.customer.code }}, {{ ticket.customer.phone }})</span>
                        <span v-if="ticket.reseller && viewer !== 'reseller'">· Reseller: {{ ticket.reseller.name }}</span>
                        <span v-if="ticket.connection">· Connection {{ ticket.connection.code }}</span>
                    </div>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600" title="Close" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>

            <div v-if="isAdmin" class="mt-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                <select :value="ticket.status" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="setStatus($event.target.value)">
                    <option v-for="s in statusChoices" :key="s" :value="s">{{ label(s) }}</option>
                </select>
                <select :value="ticket.priority" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="update('priority', $event.target.value)">
                    <option v-for="p in ['low', 'normal', 'high', 'urgent']" :key="p" :value="p">{{ label(p) }} priority</option>
                </select>
                <select :value="ticket.category" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="update('category', $event.target.value)">
                    <option v-for="c in categories" :key="c" :value="c">{{ label(c) }}</option>
                </select>
                <select :value="ticket.assigned_to || ''" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="update('assigned_to', $event.target.value)">
                    <option value="">Unassigned</option>
                    <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </div>
            <div v-else-if="statusChoices.length" class="mt-3 flex flex-wrap gap-2">
                <template v-if="isRequester">
                    <button v-if="ticket.status !== 'resolved'" type="button" class="rounded-md border border-emerald-300 px-2.5 py-1 text-xs text-emerald-700" @click="setStatus('resolved')"><i class="bi bi-check2"></i> Problem solved</button>
                    <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-600" @click="setStatus('closed')">Close ticket</button>
                </template>
                <select v-else :value="ticket.status" class="rounded-md border border-slate-300 px-2 py-1 text-xs" @change="setStatus($event.target.value)">
                    <option v-for="s in statusChoices" :key="s" :value="s">{{ label(s) }}</option>
                </select>
            </div>
        </div>

        <div ref="threadEl" class="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4" style="max-height: 55vh">
            <template v-for="r in replies" :key="r.id">
                <div v-if="r.author_type === 'system'" class="text-center text-[11px] text-slate-400">{{ r.message }} · {{ fmtDate(r.created_at) }}</div>
                <div v-else class="flex" :class="mine(r) ? 'justify-end' : 'justify-start'">
                    <div
                        class="max-w-[85%] rounded-lg border px-3 py-2 text-sm shadow-sm"
                        :class="r.is_internal ? 'border-amber-300 bg-amber-50' : mine(r) ? 'border-brand-200 bg-brand-50' : 'border-slate-200 bg-white'"
                    >
                        <div class="mb-1 text-[11px] text-slate-500">
                            <span class="font-medium text-slate-700">{{ who(r) }}</span>
                            · {{ fmtDate(r.created_at) }} {{ String(r.created_at).slice(11, 16) }}
                            <span v-if="r.is_internal" class="ms-1 rounded bg-amber-200 px-1 text-[10px] text-amber-900">internal note</span>
                        </div>
                        <div class="whitespace-pre-wrap break-words text-slate-800">{{ r.message }}</div>
                        <a v-if="r.attachment" :href="r.attachment_url" target="_blank" rel="noopener" class="mt-2 block">
                            <img v-if="isImage(r.attachment)" :src="r.attachment_url" class="max-h-48 rounded border border-slate-200" />
                            <span v-else class="text-xs text-brand-600 underline"><i class="bi bi-paperclip"></i> Attachment</span>
                        </a>
                    </div>
                </div>
            </template>
        </div>

        <form v-if="!closed" class="space-y-2 border-t border-slate-200 p-3" @submit.prevent="send">
            <textarea v-model="reply.message" required rows="3" :placeholder="reply.internal ? 'Internal note (customer and reseller will not see this)' : 'Write a reply…'" class="w-full rounded-md border px-3 py-2 text-sm" :class="reply.internal ? 'border-amber-300 bg-amber-50' : 'border-slate-300'"></textarea>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-600">
                    <input ref="fileInput" type="file" accept="image/*,.pdf" class="max-w-[200px] text-xs" @change="reply.file = $event.target.files[0] || null" />
                    <label v-if="isAdmin" class="flex items-center gap-1"><input v-model="reply.internal" type="checkbox" /> Internal note</label>
                    <select v-if="isAdmin && !reply.internal" v-model="reply.status" class="rounded-md border border-slate-300 px-2 py-1 text-xs">
                        <option value="">Keep status</option>
                        <option value="waiting">…and wait for reply</option>
                        <option value="resolved">…and mark resolved</option>
                    </select>
                </div>
                <button type="submit" :disabled="reply.saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white disabled:opacity-50"><i class="bi bi-send"></i> {{ reply.internal ? 'Add note' : 'Send' }}</button>
            </div>
        </form>
        <div v-else class="border-t border-slate-200 p-3 text-center text-xs text-slate-500">This ticket is closed.<span v-if="!isAdmin"> Open a new ticket if you need more help.</span></div>
    </div>
</template>
