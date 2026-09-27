<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import SearchSelect from '../SearchSelect.vue';
import StatusBadge from './StatusBadge.vue';
import { money, fmtDateTime, PAYMENT_METHODS, useApiError, fmtMoney } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// Pay for a connection by cycles: the unpaid bill first, then extra cycles in advance.
// Pay first, service after — the time starts when the payment is received.
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    connectionId: { type: [Number, null], default: null },
    // admin only: start the unpaid bill's time now, the customer pays later
    canCredit: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'saved']);
const toast = useToast();
const showError = useApiError();

const quote = ref(null);
const banks = ref([]);
const bank = ref(null);
const saving = ref(false);
const form = reactive({ cycles: 1, method: 'cash', transaction_id: '', notes: '' });

const option = computed(() => quote.value?.options.find((o) => o.cycles === Number(form.cycles)) || null);
const cycleLabel = (months) => (months === 0 ? 'Due only' : months === 1 ? '1 month' : `${months} months`);
const creditable = computed(() => props.canCredit && (quote.value?.open || []).some((i) => !i.credit_at));

async function startOnDue() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/connection-credit', { id: props.connectionId, note: form.notes });
        toast.success(res.data.message);
        emit('saved', null);
        close();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

async function load() {
    quote.value = null;
    if (!props.connectionId) return;
    try {
        const res = await axios.post('/isp/connection-pay-quote', { id: props.connectionId });
        quote.value = res.data;
        Object.assign(form, { cycles: res.data.options[0]?.cycles ?? 1, method: 'cash', transaction_id: '', notes: '' });
        bank.value = null;
    } catch (err) {
        showError(err);
        close();
    }
}
watch(() => [props.modelValue, props.connectionId], ([open]) => open && load());

function close() {
    emit('update:modelValue', false);
}

async function save() {
    saving.value = true;
    try {
        const res = await axios.post('/isp/connection-pay', {
            id: props.connectionId,
            cycles: form.cycles,
            method: form.method,
            bank_id: form.method === 'cash' ? null : bank.value?.id,
            transaction_id: form.transaction_id,
            notes: form.notes,
        });
        toast.success(res.data.message);
        emit('saved', res.data.id);
        close();
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    axios.post('/get-bank').then((res) => (banks.value = res.data));
});
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-[2120] flex justify-end">
            <div class="absolute inset-0 bg-slate-900/40" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-slate-50 shadow-2xl animate-slide-in sm:w-[480px]">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                    <h2 class="min-w-0 truncate text-base font-bold text-slate-800">
                        <i class="bi bi-cash-coin"></i> Pay — {{ quote?.connection?.code }}
                        <span class="text-sm font-normal text-slate-400">{{ quote?.connection?.customer?.name }}</span>
                    </h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="bi bi-x-lg"></i></button>
                </div>

                <div v-if="!quote" class="p-6 text-center text-slate-400">Loading…</div>
                <form v-else class="flex-1 space-y-4 overflow-y-auto p-5 text-sm" @submit.prevent="save">
                    <div class="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-white p-3">
                        <div>
                            <div class="text-[11px] uppercase text-slate-400">Package</div>
                            <div class="font-medium">{{ quote.connection.package?.name }}</div>
                            <div class="text-xs text-slate-500">{{ fmtMoney(quote.charge) }} / {{ cycleLabel(quote.cycle_months) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase text-slate-400">Status</div>
                            <StatusBadge :status="quote.connection.status" />
                            <div class="mt-0.5 text-xs text-slate-500">Paid until: <b>{{ fmtDateTime(quote.connection.expire_at) || 'not paid yet' }}</b></div>
                        </div>
                    </div>

                    <div v-for="i in quote.open.filter((x) => x.credit_at)" :key="`c${i.id}`" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-800">
                        <i class="bi bi-hourglass-split"></i>
                        {{ i.invoice_no }} — {{ fmtMoney(i.due) }} is running <b>on due</b> since {{ fmtDateTime(i.credit_at) }}. Paying it does not add time; no renewal bill is issued until it is paid.
                    </div>
                    <div v-if="quote.open.some((x) => !x.credit_at)" class="rounded-lg border border-red-200 bg-red-50 p-3 text-red-700">
                        <i class="bi bi-exclamation-circle"></i>
                        Unpaid bill {{ quote.open.filter((x) => !x.credit_at).map((i) => i.invoice_no).join(', ') }} — {{ fmtMoney(quote.open.filter((x) => !x.credit_at).reduce((s, i) => s + Number(i.due), 0)) }}.
                        The service does not run until it is paid<template v-if="creditable"> (or started on due below)</template>.
                    </div>
                    <div v-if="quote.advance > 0" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-800">Advance credit {{ fmtMoney(quote.advance) }} is used first.</div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">How long to pay for</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="o in quote.options"
                                :key="o.cycles"
                                type="button"
                                class="rounded-md border px-2 py-1.5 text-start"
                                :class="Number(form.cycles) === o.cycles ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-300 bg-white hover:bg-slate-50'"
                                @click="form.cycles = o.cycles"
                            >
                                <div class="font-medium">{{ cycleLabel(o.months) }}</div>
                                <div class="text-xs text-slate-500">{{ fmtMoney(o.amount) }}</div>
                            </button>
                        </div>
                    </div>

                    <div v-if="option" class="rounded-lg border border-slate-200 bg-white p-3">
                        <div class="flex justify-between"><span class="text-slate-500">To pay now</span><b class="text-base">{{ fmtMoney(option.amount) }}</b></div>
                        <div class="mt-1 flex justify-between">
                            <span class="text-slate-500">Paid until / next bill due</span>
                            <b class="text-emerald-700">{{ option.until ? fmtDateTime(option.until) : 'starts when activated' }}</b>
                        </div>
                        <div v-if="quote.bonus_days" class="mt-1 text-xs text-slate-500">Includes {{ quote.bonus_days }} bonus day(s) on this first payment.</div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
                            <select v-model="form.method" class="w-full rounded-md border border-slate-300 px-3 py-1.5">
                                <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Transaction ID</label>
                            <input v-model="form.transaction_id" type="text" :required="['bkash', 'nagad', 'rocket'].includes(form.method)" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                        </div>
                    </div>
                    <div v-if="form.method !== 'cash'">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Received into account</label>
                        <SearchSelect :options="banks" v-model="bank" label="display_name" placeholder="Bank / bKash / Nagad account" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input v-model="form.notes" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5" />
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-1.5" @click="close">Cancel</button>
                        <button v-if="creditable" type="button" :disabled="saving" class="rounded-md border border-amber-500 px-4 py-1.5 font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50" title="Start the service now; the bill stays as the customer's due" @click="startOnDue">
                            <i class="bi bi-hourglass-split"></i> Start on due (pay later)
                        </button>
                        <button type="submit" :disabled="saving || !option" class="rounded-md bg-emerald-600 px-5 py-1.5 font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            <i class="bi bi-check2-circle"></i> {{ option && option.amount > 0 ? `Pay ${fmtMoney(option.amount)}` : 'Confirm' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
.animate-slide-in {
    animation: slide-in 0.25s ease-out;
}
</style>
