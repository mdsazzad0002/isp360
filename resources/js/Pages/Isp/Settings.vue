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
// the chosen country's pack: its defaults are shown, and applied only on request
const pack = computed(() => country.value?.pack);
const packOptions = ref({ tax: false, billing: false });
const applying = ref(false);
async function applyPack() {
    const c = country.value;
    if (!c || !confirm(`Apply the ${c.name} country pack to the whole company?`)) return;
    applying.value = true;
    try {
        const res = await axios.post('/isp/country-pack', { country_code: c.code, ...packOptions.value });
        toast.success(res.data.message);
        s.value = (await axios.post('/isp/get-settings')).data;
        router.reload({ only: ['currency', 'timezone', 'defaultLocale'] });
    } catch (err) {
        showError(err);
    } finally {
        applying.value = false;
    }
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

                <div v-if="pack" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <h3 class="mb-2 text-xs font-semibold text-slate-700">
                        {{ pack.name }} country pack
                        <span class="font-normal text-slate-400">{{ pack.full ? '' : '(generic: currency and timezone only)' }}</span>
                    </h3>
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-1 text-xs md:grid-cols-2">
                        <div><dt class="inline text-slate-500">Currency / timezone:</dt> <dd class="inline">{{ pack.currency }}, {{ pack.timezone }}</dd></div>
                        <div><dt class="inline text-slate-500">Language / date format:</dt> <dd class="inline">{{ pack.language }}, {{ pack.date_format }}</dd></div>
                        <div v-if="pack.phone.calling_code"><dt class="inline text-slate-500">Phone:</dt> <dd class="inline">+{{ pack.phone.calling_code }}, e.g. {{ pack.phone.example }}</dd></div>
                        <div><dt class="inline text-slate-500">Address:</dt> <dd class="inline">{{ pack.address.state_label }}, {{ pack.address.postcode_label }}{{ pack.address.postcode_required ? ' (required)' : '' }}</dd></div>
                        <div><dt class="inline text-slate-500">Customer ID types:</dt> <dd class="inline">{{ Object.values(pack.id_types).join(', ') }}</dd></div>
                        <div>
                            <dt class="inline text-slate-500">Tax:</dt>
                            <dd class="inline">{{ pack.tax.label }}, {{ pack.tax.prices_include_tax ? 'included in prices' : 'added to prices' }}; {{ pack.tax.rates.length ? pack.tax.rates.map((r) => r.name).join(' + ') : 'no suggested rate' }}</dd>
                        </div>
                        <div v-if="pack.payment_gateways.length">
                            <dt class="inline text-slate-500">Payment gateways:</dt>
                            <dd class="inline">{{ pack.available_gateways.join(', ') || 'none built yet' }}<span v-if="pack.payment_gateways.length > pack.available_gateways.length" class="text-slate-400"> (planned: {{ pack.payment_gateways.filter((g) => !pack.available_gateways.includes(g)).join(', ') }})</span></dd>
                        </div>
                        <div v-if="pack.sms_providers.length"><dt class="inline text-slate-500">SMS providers:</dt> <dd class="inline">{{ pack.sms_providers.join(', ') }}</dd></div>
                        <div><dt class="inline text-slate-500">Session log retention:</dt> <dd class="inline">{{ pack.log_retention_days ? `${pack.log_retention_days} days` : 'no legal minimum' }}</dd></div>
                        <div v-if="pack.regulatory_reports.length"><dt class="inline text-slate-500">Regulatory reports:</dt> <dd class="inline">{{ pack.regulatory_reports.join(', ') }}</dd></div>
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="packOptions.tax" type="checkbox" /> Also set tax name, pricing and suggested rates</label>
                        <label v-if="Object.keys(pack.billing).length" class="flex items-center gap-2"><input v-model="packOptions.billing" type="checkbox" /> Also set billing defaults on every branch</label>
                        <button type="button" :disabled="applying" class="ml-auto rounded-md border border-brand-500 px-3 py-1.5 text-xs font-medium text-brand-600 hover:bg-brand-50 disabled:opacity-50" @click="applyPack">Apply country pack</button>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Nothing recorded changes: the currency and timezone stay once money exists, and tax rates are added only when the company has none. Check suggested tax rates with your accountant.</p>
                </div>
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
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Login security <span class="font-normal text-slate-400">(whole company)</span></h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Two-factor login (authenticator app)</label>
                        <select v-model="s.two_factor_policy" :class="input">
                            <option v-for="p in s.two_factor_policies" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    Anyone who must use it and hasn't set it up is sent to set it up at their next page. Everyone can turn it on for themselves under My profile.
                    <template v-if="!s.my_two_factor"> Turn it on for your own account first before requiring it.</template>
                    Five wrong passwords lock that username for 15 minutes from the same address.
                </p>
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
                        <label class="mb-1 block text-xs font-medium text-slate-600">Postpaid packages: bill due, days into its period</label>
                        <input v-model="s.postpaid_due_days" type="number" min="0" max="60" :class="input" />
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
                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Grace period (days after the paid time)</label>
                        <input v-model="s.grace_days" type="number" min="0" max="60" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Notice SMS, days before suspension <span class="text-slate-400">(0 = none)</span></label>
                        <input v-model="s.notice_days" type="number" min="0" max="30" :class="input" />
                    </div>
                    <label class="flex items-center gap-2 pt-5 text-sm"><input v-model="s.notice_required" type="checkbox" :disabled="!(s.notice_days > 0)" /> Never suspend sooner than that after the notice</label>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    <template v-if="s.grace_days > 0">Example: paid until 5 Nov 2:30 PM → the line stays on for {{ s.grace_days }} more day(s) and goes off at 2:30 PM on the last grace day.</template>
                    <template v-else>No grace period. Example: paid until 5 Nov 2:30 PM → the line goes off at 5 Nov 2:30 PM (checked every minute).</template>
                    It comes back the moment the renewal is paid.
                    <template v-if="s.notice_days > 0">A notice SMS goes out {{ s.notice_days }} day(s) before the suspension{{ s.notice_required ? ", and a line is never suspended sooner than that after its notice (for countries that require notice)" : '' }}.</template>
                </p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Late fee</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Late fee</label>
                        <select v-model="s.late_fee_type" :class="input">
                            <option value="none">None</option>
                            <option value="fixed">Fixed amount ({{ cur() }})</option>
                            <option value="percent">Percent of the unpaid amount</option>
                        </select>
                    </div>
                    <template v-if="s.late_fee_type !== 'none'">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ s.late_fee_type === 'percent' ? 'Late fee (%)' : `Late fee (${cur()})` }}</label>
                            <input v-model="s.late_fee_amount" type="number" min="0" step="0.01" :class="input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Charged when unpaid, days after the due date</label>
                            <input v-model="s.late_fee_after_days" type="number" min="0" max="365" :class="input" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Repeat</label>
                            <select v-model="s.late_fee_repeat" :class="input">
                                <option value="once">Once per invoice</option>
                                <option value="monthly">Every 30 days while unpaid</option>
                            </select>
                        </div>
                        <div v-if="s.late_fee_repeat === 'monthly'">
                            <label class="mb-1 block text-xs font-medium text-slate-600">At most, per invoice</label>
                            <input v-model="s.late_fee_max" type="number" min="1" max="24" :class="input" />
                        </div>
                    </template>
                </div>
                <p class="mt-2 text-xs text-slate-500">Added to the unpaid invoice as a debit note (in the ledger and on the customer statement), without tax. A percent fee is on the unpaid amount before earlier late fees. Credit notes can waive it.</p>
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
                <p class="mb-3 text-xs text-slate-500">Sent through the active SMS gateway. Placeholders: {name} {code} {currency} {balance} {invoice} {amount} {due_date} {receipt} {connection} {expire_date} {suspend_date}</p>
                <div class="space-y-3">
                    <div v-for="k in [['invoice', 'Invoice generated'], ['payment', 'Payment received'], ['suspend', 'Connection suspended'], ['reactivate', 'Connection reactivated'], ['notice', 'Notice before suspension']]" :key="k[0]" class="grid grid-cols-1 gap-2 md:grid-cols-5">
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
