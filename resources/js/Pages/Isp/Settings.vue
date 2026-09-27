<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();
const s = ref(null);
const saving = ref(false);

onMounted(() => axios.post('/isp/get-settings').then((r) => (s.value = r.data)));

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/settings', s.value);
        toast.success(res.data.message);
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
const input = 'w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm';
</script>

<template>
    <div class="p-4">
        <form v-if="s" class="space-y-3" @submit.prevent="save">
            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Prepaid billing</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.auto_invoice" type="checkbox" /> Issue renewal invoices automatically</label>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Renewal invoice, days before expiry</label>
                        <input v-model="s.renewal_invoice_days" type="number" min="0" max="30" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">New connection bonus days <span class="text-slate-400">(default, free)</span></label>
                        <input v-model="s.init_bonus_days" type="number" min="0" max="365" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Manual invoice due = invoice date + days</label>
                        <input v-model="s.due_days" type="number" min="0" max="90" :class="input" />
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">A package bill buys one billing cycle (1, 3, 6 or 12 months). The time starts the moment the bill is fully paid — or when the current paid time ends, if that is later — and runs to the same date and time. A new connection is billed when it is created.</p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Expiry &amp; suspension</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.auto_suspend" type="checkbox" /> Auto-suspend when the expire date passes</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.auto_reactivate" type="checkbox" /> Auto-reactivate when payment extends the expire date</label>
                </div>
                <p class="mt-2 text-xs text-slate-500">No grace period. Example: paid until 5 Nov 2:30 PM → the line goes off at 5 Nov 2:30 PM (checked every minute) and comes back the moment the renewal is paid.</p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Referral commission</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.referral_enabled" type="checkbox" /> Reward customers who refer a new customer</label>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Commission type</label>
                        <select v-model="s.referral_commission_type" :class="input">
                            <option value="fixed">Fixed amount (Tk)</option>
                            <option value="percent">Percent of the first bill</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ s.referral_commission_type === 'percent' ? 'Commission (%)' : 'Commission (Tk)' }}</label>
                        <input v-model="s.referral_commission" type="number" min="0" step="0.01" :class="input" />
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">Pick the referrer ("Referred by") on the new connection form. When the new customer's first bill is fully paid, the commission goes to the referrer's wallet (advance credit) and pays their next bills automatically. Once per new customer; no cash-book entry.</p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Numbering prefixes</h2>
                <div class="grid grid-cols-3 gap-3 md:grid-cols-6">
                    <div v-for="k in [['invoice_prefix', 'Invoice'], ['receipt_prefix', 'Receipt'], ['credit_note_prefix', 'Credit note'], ['debit_note_prefix', 'Debit note'], ['refund_prefix', 'Refund'], ['connection_prefix', 'Connection']]" :key="k[0]">
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ k[1] }}</label>
                        <input v-model="s[k[0]]" maxlength="8" :class="input" />
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-1 text-sm font-semibold text-slate-700">Customer SMS</h2>
                <p class="mb-3 text-xs text-slate-500">Sent through the active SMS gateway. Placeholders: {name} {code} {balance} {invoice} {amount} {due_date} {receipt} {connection}</p>
                <div class="space-y-3">
                    <div v-for="k in [['invoice', 'Invoice generated'], ['payment', 'Payment received'], ['suspend', 'Connection suspended'], ['reactivate', 'Connection reactivated']]" :key="k[0]" class="grid grid-cols-1 gap-2 md:grid-cols-5">
                        <label class="flex items-center gap-2 text-sm"><input v-model="s['sms_' + k[0]]" type="checkbox" /> {{ k[1] }}</label>
                        <textarea v-model="s['sms_tpl_' + k[0]]" rows="2" maxlength="320" class="md:col-span-4" :class="input"></textarea>
                    </div>
                </div>
            </section>

            <div class="flex justify-end">
                <button type="submit" :disabled="saving" class="rounded-md bg-brand-500 px-6 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">Save settings</button>
            </div>
        </form>
    </div>
</template>
