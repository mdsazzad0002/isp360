<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import QRCode from 'qrcode';
import { useToast } from '../lib/toast';
import { useApiError } from '../lib/isp';

// The signed-in account's own two-factor login: /two-factor (staff) or /reseller/two-factor.
const props = defineProps({ base: { type: String, default: '/two-factor' } });
const emit = defineEmits(['enabled']);
const toast = useToast();
const showError = useApiError();

const status = ref(null);
const password = ref('');
const setup = ref(null); // { secret, qr } while confirming
const code = ref('');
const recoveryCodes = ref(null);
const busy = ref(false);

const load = async () => (status.value = (await axios.post(`${props.base}/status`)).data);
onMounted(load);

async function run(fn) {
    busy.value = true;
    try {
        await fn();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}

const enable = () =>
    run(async () => {
        const { data } = await axios.post(`${props.base}/enable`, { password: password.value });
        setup.value = { secret: data.secret, qr: await QRCode.toDataURL(data.uri, { width: 200, margin: 1 }) };
        password.value = '';
        code.value = '';
    });

const confirm = () =>
    run(async () => {
        const { data } = await axios.post(`${props.base}/confirm`, { code: code.value });
        toast.success(data.message);
        recoveryCodes.value = data.recovery_codes;
        setup.value = null;
        await load();
        emit('enabled');
    });

const newCodes = () =>
    run(async () => {
        const { data } = await axios.post(`${props.base}/recovery-codes`, { password: password.value });
        toast.success(data.message);
        recoveryCodes.value = data.recovery_codes;
        password.value = '';
        await load();
    });

const disable = () =>
    run(async () => {
        const { data } = await axios.post(`${props.base}/disable`, { password: password.value });
        toast.success(data.message);
        password.value = '';
        recoveryCodes.value = null;
        await load();
    });

function copyCodes() {
    navigator.clipboard?.writeText(recoveryCodes.value.join('\n')).then(() => toast.success('Recovery codes copied'));
}
const input = 'w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm';
const button = 'rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50';
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <i class="bi bi-shield-lock"></i> Two-factor login
            <span v-if="status" class="rounded-full px-2 py-0.5 text-xs font-medium" :class="status.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ status.enabled ? 'On' : 'Off' }}</span>
            <span v-if="status?.required" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Required for your account</span>
        </h2>
        <p class="mb-3 text-xs text-slate-500">After your password, you also enter a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).</p>

        <div v-if="recoveryCodes" class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3">
            <p class="mb-2 text-xs font-medium text-amber-800">Save these recovery codes somewhere safe. Each one logs you in once if you lose your phone. They won't be shown again.</p>
            <div class="grid grid-cols-2 gap-1 font-mono text-sm text-slate-800 sm:grid-cols-4">
                <span v-for="c in recoveryCodes" :key="c">{{ c }}</span>
            </div>
            <button type="button" class="mt-2 text-xs text-amber-800 underline" @click="copyCodes">Copy codes</button>
        </div>

        <template v-if="status && !status.enabled">
            <div v-if="setup" class="flex flex-col gap-4 sm:flex-row">
                <img :src="setup.qr" alt="QR code for your authenticator app" class="h-40 w-40 rounded border border-slate-200" />
                <div class="flex-1 space-y-2">
                    <p class="text-sm text-slate-600">1. Scan the QR code with your authenticator app, or type this key:</p>
                    <p class="break-all rounded bg-slate-50 px-2 py-1 font-mono text-sm">{{ setup.secret }}</p>
                    <p class="text-sm text-slate-600">2. Enter the 6-digit code the app shows:</p>
                    <form class="flex gap-2" @submit.prevent="confirm">
                        <input v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" :class="input" class="max-w-40 tracking-widest" required />
                        <button type="submit" :disabled="busy" :class="button" class="bg-brand-500 text-white hover:bg-brand-600">Turn on</button>
                    </form>
                </div>
            </div>
            <form v-else class="flex flex-wrap gap-2" @submit.prevent="enable">
                <input v-model="password" type="password" autocomplete="current-password" placeholder="Your password" :class="input" class="max-w-60" required />
                <button type="submit" :disabled="busy" :class="button" class="bg-brand-500 text-white hover:bg-brand-600">Set up two-factor login</button>
            </form>
        </template>

        <form v-else-if="status" class="flex flex-wrap items-center gap-2" @submit.prevent>
            <span class="me-2 text-xs text-slate-500">{{ status.recovery_codes_left }} recovery codes left.</span>
            <input v-model="password" type="password" autocomplete="current-password" placeholder="Your password" :class="input" class="max-w-60" />
            <button type="button" :disabled="busy || !password" :class="button" class="border border-slate-300 hover:bg-slate-50" @click="newCodes">New recovery codes</button>
            <button v-if="!status.required" type="button" :disabled="busy || !password" :class="button" class="border border-red-300 text-red-600 hover:bg-red-50" @click="disable">Turn off</button>
        </form>
    </section>
</template>
