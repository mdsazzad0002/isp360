<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { useToast } from '../lib/toast';
import { useApiError, fmtDateTime } from '../lib/isp';

// Where the signed-in account is signed in: /my-sessions (staff), /reseller/my-sessions or
// /customer-portal/my-sessions. Other browsers can be signed out one by one or all at once.
const props = defineProps({ base: { type: String, default: '/my-sessions' } });
const toast = useToast();
const showError = useApiError();
const sessions = ref(null);
const busy = ref(false);

const load = async () => (sessions.value = (await axios.post(props.base)).data);
onMounted(load);

// "Chrome on Windows" from a user agent; the raw string is in the tooltip
function browser(ua) {
    if (!ua) return 'Unknown browser';
    const name = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
    return os ? `${name} on ${os}` : name;
}

async function revoke(payload) {
    busy.value = true;
    try {
        toast.success((await axios.post(`${props.base}/revoke`, payload)).data.message);
        await load();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-700"><i class="bi bi-laptop"></i> Where you're signed in</h2>
            <button v-if="sessions && sessions.length > 1" type="button" :disabled="busy" class="rounded-md border border-red-300 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50" @click="revoke({ others: true })">
                Sign out all other sessions
            </button>
        </div>
        <p class="mb-3 text-xs text-slate-500">If you see a browser you don't recognise, sign it out and change your password.</p>
        <ul v-if="sessions" class="divide-y divide-slate-100 text-sm">
            <li v-for="s in sessions" :key="s.id" class="flex items-center justify-between gap-3 py-2">
                <div class="min-w-0">
                    <div class="font-medium text-slate-800" :title="s.user_agent">
                        {{ browser(s.user_agent) }}
                        <span v-if="s.current" class="ms-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">This browser</span>
                    </div>
                    <div class="text-xs text-slate-500">{{ s.ip_address || '—' }} · last active {{ fmtDateTime(s.last_seen_at) }}</div>
                </div>
                <button v-if="!s.current" type="button" :disabled="busy" class="shrink-0 rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-50 disabled:opacity-50" @click="revoke({ id: s.id })">Sign out</button>
            </li>
        </ul>
    </section>
</template>
