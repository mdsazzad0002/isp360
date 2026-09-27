<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError, GATEWAY_STYLES, cur } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const props = defineProps({ ipnUrl: { type: String, default: '' } });
const toast = useToast();
const showError = useApiError();

const gateways = ref([]);
const banks = ref([]);
const saving = ref('');
const open = ref('');

const MODE_HELP = {
    api: 'Customers pay on the gateway page and the payment is confirmed automatically.',
    manual: 'Customers send money to your number and enter the Transaction ID. You approve it from Online Payments.',
};

function load() {
    axios.post('/isp/get-payment-gateways').then((res) => {
        gateways.value = res.data.gateways;
        banks.value = res.data.banks;
        if (!open.value && gateways.value.length) open.value = gateways.value[0].gateway;
    });
}

async function save(g) {
    saving.value = g.gateway;
    try {
        const res = await axios.post('/isp/payment-gateway', {
            gateway: g.gateway,
            is_active: g.is_active,
            mode: g.mode,
            sandbox: g.sandbox,
            credentials: Object.fromEntries(Object.keys(g.fields).map((f) => [f, g.credentials[f]])),
            manual_number: g.manual_number,
            manual_account_type: g.manual_account_type,
            instructions: g.instructions,
            bank_id: g.bank_id,
            min_amount: g.min_amount,
            max_amount: g.max_amount,
            sort: g.sort,
        });
        toast.success(res.data.message);
        load();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = '';
    }
}

onMounted(load);
</script>

<template>
    <div class="p-4">
        <div class="mb-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="text-base font-semibold text-slate-800">Payment Gateways</h1>
            <p class="mt-1 text-sm text-slate-500">
                Choose how customers can pay from the customer portal. Payments go into the selected receiving account and are applied to the oldest open bills;
                anything extra stays in the customer's wallet for the next bills.
            </p>
        </div>

        <div class="space-y-3">
            <div v-for="g in gateways" :key="g.gateway" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left" @click="open = open === g.gateway ? '' : g.gateway">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg font-bold text-white" :class="GATEWAY_STYLES[g.gateway].badge">
                        {{ GATEWAY_STYLES[g.gateway].initial }}
                    </span>
                    <span class="flex-1">
                        <span class="block font-semibold text-slate-800">{{ g.label }}</span>
                        <span class="block text-xs text-slate-500">
                            {{ g.mode === 'api' ? 'Automatic (API)' : 'Manual (Transaction ID)' }}
                            <template v-if="g.mode === 'api'"> · {{ g.sandbox ? 'Sandbox' : 'Live' }}</template>
                            <template v-if="!g.currency_ok"> · <span class="text-amber-600">only takes {{ g.currencies.join(', ') }}</span></template>
                        </span>
                    </span>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="g.is_active && g.usable ? 'bg-emerald-100 text-emerald-700' : g.is_active ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'"
                    >
                        {{ g.is_active && g.usable ? 'On' : g.is_active ? 'Incomplete' : 'Off' }}
                    </span>
                    <i class="bi bi-chevron-down text-slate-400 transition-transform" :class="open === g.gateway ? 'rotate-180' : ''"></i>
                </button>

                <form v-if="open === g.gateway" class="space-y-4 border-t border-slate-100 p-4" @submit.prevent="save(g)">
                    <div class="flex flex-wrap items-center gap-6">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input v-model="g.is_active" type="checkbox" class="h-4 w-4" /> Show to customers
                        </label>
                        <div v-if="g.modes.length > 1" class="flex rounded-md border border-slate-200 p-0.5 text-sm">
                            <button
                                v-for="m in g.modes"
                                :key="m"
                                type="button"
                                class="rounded px-3 py-1 font-medium transition"
                                :class="g.mode === m ? 'bg-brand-500 text-white' : 'text-slate-500 hover:bg-slate-50'"
                                @click="g.mode = m"
                            >
                                {{ m === 'api' ? 'Automatic (API)' : 'Manual' }}
                            </button>
                        </div>
                        <label v-if="g.mode === 'api'" class="flex items-center gap-2 text-sm text-slate-600">
                            <input v-model="g.sandbox" type="checkbox" class="h-4 w-4" /> Sandbox / test mode
                        </label>
                    </div>
                    <p class="-mt-2 text-xs text-slate-500">{{ MODE_HELP[g.mode] }}</p>

                    <!-- API credentials -->
                    <div v-if="g.mode === 'api'" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div v-for="(labelText, field) in g.fields" :key="field" :class="g.gateway === 'nagad' && field.endsWith('_key') ? 'md:col-span-2' : ''">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ labelText }}</label>
                            <textarea
                                v-if="g.gateway === 'nagad' && field.endsWith('_key')"
                                v-model="g.credentials[field]"
                                rows="3"
                                :placeholder="g.credentials['has_' + field] ? 'Saved. Leave blank to keep it.' : 'Paste the key'"
                                class="w-full rounded-md border border-slate-300 px-3 py-1.5 font-mono text-xs"
                            ></textarea>
                            <input
                                v-else
                                v-model="g.credentials[field]"
                                :type="g.secret.includes(field) ? 'password' : 'text'"
                                autocomplete="new-password"
                                :placeholder="g.secret.includes(field) && g.credentials['has_' + field] ? 'Saved. Leave blank to keep it.' : ''"
                                class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                            />
                        </div>
                        <p v-if="g.gateway === 'sslcommerz'" class="text-xs text-slate-500 md:col-span-2">
                            IPN URL for the SSLCommerz merchant panel: <code class="rounded bg-slate-100 px-1.5 py-0.5">{{ ipnUrl }}</code>
                        </p>
                        <p v-if="g.gateway === 'paypal'" class="text-xs text-slate-500 md:col-span-2">
                            Create a REST app in the PayPal developer dashboard (sandbox app in sandbox mode) and copy its client ID and secret.
                            <template v-if="g.webhook_url">Add a webhook to the app with the URL <code class="break-all rounded bg-slate-100 px-1.5 py-0.5">{{ g.webhook_url }}</code> and the events
                                <code>CHECKOUT.ORDER.APPROVED</code>, <code>PAYMENT.CAPTURE.COMPLETED</code>, <code>PAYMENT.CAPTURE.DENIED</code>, then paste its Webhook ID above. It finishes payments whose customer closed the browser before returning.</template>
                            <template v-else>Save once to get the webhook URL.</template>
                        </p>
                        <p v-if="g.gateway === 'stripe'" class="text-xs text-slate-500 md:col-span-2">
                            <template v-if="g.webhook_url">
                                In the Stripe dashboard (Developers → Webhooks) add the endpoint <code class="break-all rounded bg-slate-100 px-1.5 py-0.5">{{ g.webhook_url }}</code>
                                with the events <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code> and <code>checkout.session.expired</code>, then paste its signing secret above.
                            </template>
                            <template v-else>Save once to get the webhook URL for the Stripe dashboard.</template>
                            Use test keys (sk_test_…) in sandbox mode and live keys (sk_live_…) otherwise. Customers pay on Stripe's own page; card details never reach this server.
                        </p>
                    </div>

                    <!-- Manual mode -->
                    <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ g.label }} number customers pay to</label>
                            <input v-model="g.manual_number" placeholder="01XXXXXXXXX" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Account type</label>
                            <select v-model="g.manual_account_type" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                <option value="personal">Personal (Send Money)</option>
                                <option value="agent">Agent (Cash Out)</option>
                                <option value="merchant">Merchant (Payment)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Receiving account (cash / bank book)</label>
                            <select v-model="g.bank_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                <option :value="null">Select account</option>
                                <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}{{ b.number ? ` (${b.number})` : '' }}{{ b.bank_name ? ` · ${b.bank_name}` : '' }}</option>
                            </select>
                            <p v-if="!banks.length" class="mt-1 text-xs text-amber-600">No accounts yet. Add one under Finance → Banking → Bank Entry.</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Minimum ({{ cur() }})</label>
                            <input v-model="g.min_amount" type="number" min="1" step="1" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Maximum ({{ cur() }})</label>
                            <input v-model="g.max_amount" type="number" min="1" step="1" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div class="md:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-slate-600">Instructions shown to customers <span class="font-normal text-slate-400">(optional)</span></label>
                            <textarea
                                v-model="g.instructions"
                                rows="2"
                                :placeholder="g.mode === 'manual' ? 'e.g. Use your customer code as the reference.' : 'e.g. A 1.5% gateway charge may apply.'"
                                class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                            ></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                            <input v-model="g.sort" type="number" min="0" max="99" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" :disabled="saving === g.gateway" class="rounded-md bg-brand-500 px-5 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                            <i v-if="saving === g.gateway" class="bi bi-arrow-repeat animate-spin"></i> Save {{ g.label }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
