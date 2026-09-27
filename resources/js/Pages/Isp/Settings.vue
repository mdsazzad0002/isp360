<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError, cur } from '../../lib/isp';

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
        s.value = (await axios.post('/isp/get-settings')).data;
        router.reload({ only: ['currency'] });
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
const country = computed(() => s.value?.countries.find((c) => c.code === s.value.country_code));
// the chosen country's zones, plus the saved one when it is locked to another country's zone
const zones = computed(() => {
    const list = country.value?.timezones || [];
    return s.value && !list.includes(s.value.timezone) ? [s.value.timezone, ...list] : list;
});

// picking a country pre-selects its currency and main timezone, while both can still change
function onCountry() {
    const c = country.value;
    if (!c || s.value.currency_locked) return;
    if (s.value.currencies.some((x) => x.code === c.currency)) s.value.currency_code = c.currency;
    s.value.timezone = c.timezone;
}
// tax rates are saved one by one, apart from the settings form
const newRate = () => ({ id: null, name: '', rate: '', is_default: true, is_active: true, sort: 0 });
const rateForm = ref(newRate());
async function saveRate(rate) {
    try {
        const res = await axios.post('/isp/tax-rate', rate);
        toast.success(res.data.message);
        s.value.tax_rates = (await axios.post('/isp/get-settings')).data.tax_rates;
        if (!rate.id) rateForm.value = newRate();
    } catch (err) {
        showError(err);
    }
}
const input = 'w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm';
</script>

<template>
    <div class="p-4">
        <form v-if="s" class="space-y-3" @submit.prevent="save">
            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Country, currency &amp; timezone <span class="font-normal text-slate-400">(whole company, every branch)</span></h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Country</label>
                        <select v-model="s.country_code" :class="input" @change="onCountry">
                            <option v-for="c in s.countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Billing currency</label>
                        <select v-model="s.currency_code" :disabled="s.currency_locked" :class="input" class="disabled:bg-slate-50">
                            <option v-for="c in s.currencies" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name }} ({{ c.symbol }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Timezone</label>
                        <select v-model="s.timezone" :disabled="s.currency_locked" :class="input" class="disabled:bg-slate-50">
                            <option v-for="z in zones" :key="z" :value="z">{{ z.replaceAll('_', ' ') }}</option>
                        </select>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    <template v-if="s.currency_locked">Currency and timezone are locked: invoices or payments already exist in {{ s.currency_code }}, {{ s.timezone }}.</template>
                    <template v-else>Set these before the first invoice. Currency and timezone lock once the first invoice or payment is recorded.</template>
                    All dates and times (expiry, bills, reports) are in this timezone, and a paid line expires at the same local time even across daylight-saving changes.
                    Payment methods that can't take this currency are hidden from customers.
                </p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Sales tax <span class="font-normal text-slate-400">(whole company)</span></h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Tax name on invoices</label>
                        <input v-model="s.tax_label" maxlength="20" placeholder="VAT, GST, Sales tax" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Company tax number <span class="text-slate-400">(VAT / GST / BIN)</span></label>
                        <input v-model="s.tax_number" maxlength="60" :class="input" />
                    </div>
                    <label class="flex items-center gap-2 pt-5 text-sm"><input v-model="s.prices_include_tax" type="checkbox" /> Package and invoice prices include tax</label>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    {{ s.prices_include_tax ? 'A price of 1,150 with 15% tax is billed as 1,150 (1,000 + 150 tax).' : 'A price of 1,000 with 15% tax is billed as 1,150.' }}
                    Changing this only affects new invoices. Resellers earn on the amount before tax.
                </p>

                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                            <th class="py-1.5 pr-2 font-medium">Rate name</th>
                            <th class="w-28 py-1.5 pr-2 font-medium">Rate %</th>
                            <th class="w-24 py-1.5 pr-2 text-center font-medium" title="Used by packages set to the default taxes and by manual invoice lines">Default</th>
                            <th class="w-20 py-1.5 pr-2 text-center font-medium">Active</th>
                            <th class="w-20 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in s.tax_rates" :key="r.id" class="border-b border-slate-100">
                            <td class="py-1 pr-2"><input v-model="r.name" maxlength="60" :class="input" /></td>
                            <td class="py-1 pr-2"><input v-model="r.rate" type="number" min="0" max="100" step="0.0001" :class="input" /></td>
                            <td class="py-1 pr-2 text-center"><input v-model="r.is_default" type="checkbox" /></td>
                            <td class="py-1 pr-2 text-center"><input v-model="r.is_active" type="checkbox" /></td>
                            <td class="py-1 text-right"><button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50" @click="saveRate(r)">Save</button></td>
                        </tr>
                        <tr>
                            <td class="py-1 pr-2"><input v-model="rateForm.name" maxlength="60" placeholder="e.g. VAT 15%" :class="input" /></td>
                            <td class="py-1 pr-2"><input v-model="rateForm.rate" type="number" min="0" max="100" step="0.0001" placeholder="15" :class="input" /></td>
                            <td class="py-1 pr-2 text-center"><input v-model="rateForm.is_default" type="checkbox" /></td>
                            <td class="py-1 pr-2 text-center"><input v-model="rateForm.is_active" type="checkbox" /></td>
                            <td class="py-1 text-right"><button type="button" class="rounded bg-brand-500 px-2 py-1 text-xs text-white hover:bg-brand-600" @click="saveRate(rateForm)">Add</button></td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-slate-500">No rates = no tax. Several default rates are all charged (e.g. CGST 9% + SGST 9%). A package can use the defaults, its own rates, or be tax exempt. Issued invoices keep the rates they were billed with.</p>
            </section>

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
                            <option value="fixed">Fixed amount ({{ cur() }})</option>
                            <option value="percent">Percent of the first bill</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ s.referral_commission_type === 'percent' ? 'Commission (%)' : `Commission (${cur()})` }}</label>
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
                <p class="mb-3 text-xs text-slate-500">Sent through the active SMS gateway. Placeholders: {name} {code} {currency} {balance} {invoice} {amount} {due_date} {receipt} {connection}</p>
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
