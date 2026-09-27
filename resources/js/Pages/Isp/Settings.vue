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
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Billing cycle</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.auto_invoice" type="checkbox" /> Generate invoices automatically</label>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Generate on day of month</label>
                        <input v-model="s.invoice_generate_day" type="number" min="1" max="28" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Which month is billed</label>
                        <select v-model="s.billing_month" :class="input">
                            <option value="current">Running month (prepaid — bill Sep on 1 Sep)</option>
                            <option value="previous">Previous month (postpaid — bill Sep on 1 Oct)</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Mid-month activation</label>
                        <select v-model="s.first_month_billing" :class="input">
                            <option value="prorate">Prorate the first month by days</option>
                            <option value="full">Charge the full first month</option>
                            <option value="next_month">Free until next month</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Due date = invoice date + days</label>
                        <input v-model="s.due_days" type="number" min="0" max="90" :class="input" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.bill_suspended" type="checkbox" /> Keep billing suspended connections</label>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Overdue &amp; suspension</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Grace period after due date (days)</label>
                        <input v-model="s.grace_days" type="number" min="0" max="90" :class="input" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.auto_suspend" type="checkbox" /> Auto-suspend after grace period</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="s.auto_reactivate" type="checkbox" /> Auto-reactivate when dues are cleared</label>
                </div>
                <p class="mt-2 text-xs text-slate-500">Example: due date 10 Oct + {{ s.grace_days }} grace days → suspended on the next nightly run after {{ 10 + Number(s.grace_days) }} Oct if still unpaid.</p>
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
