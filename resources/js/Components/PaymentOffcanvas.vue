<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import SearchSelect from './SearchSelect.vue';
import { useToast } from '../lib/toast';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    mode: { type: String, required: true }, // 'customer' | 'supplier'
    entity: { type: Object, default: () => ({}) }, // { id, name, code, phone }
});

const emit = defineEmits(['update:modelValue', 'paid']);

const toast = useToast();

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const modeLabel = computed(() => (props.mode === 'supplier' ? 'Supplier' : 'Customer'));

const banks = ref([]);
const previousDue = ref(0);
const previousDueLoading = ref(false);
const note = ref('');
const date = ref(todayStr());
const submitting = ref(false);
const done = ref(false);

function cashOption() {
    return { id: 'cash', display_name: 'Cash' };
}
function emptyRow() {
    return { method: cashOption(), last_digit: '', amount: '' };
}
const cart = ref([emptyRow()]);
const paymentMethods = computed(() => [cashOption(), ...banks.value]);

// Settling a positive due always moves money toward the shop for a customer (collect)
// and away from the shop for a supplier (pay) — and the reverse when due is negative
// (a customer credit to refund, or a supplier overpayment to recover).
const direction = computed(() => {
    const positive = previousDue.value >= 0;
    if (props.mode === 'customer') return positive ? 'receive' : 'payment';
    return positive ? 'payment' : 'receive';
});
const actionLabel = computed(() => (direction.value === 'receive' ? 'Collect' : 'Pay'));
const submitUrl = computed(() => `/${direction.value}`);
const invoiceUrl = computed(() => `/get-${direction.value}-invoice`);

function getBanks() {
    axios.post('/get-bank').then((res) => (banks.value = res.data));
}

async function fetchDue() {
    if (!props.entity?.id) {
        previousDue.value = 0;
        return;
    }
    previousDueLoading.value = true;
    try {
        const res = await axios.post(`/get-${props.mode}Due`, { [`${props.mode}Id`]: props.entity.id });
        previousDue.value = parseFloat(res.data?.[0]?.due ?? 0);
    } finally {
        previousDueLoading.value = false;
    }
}

function payableTotal() {
    return Math.abs(previousDue.value);
}
function paidSum() {
    return cart.value.reduce((pr, cu) => pr + parseFloat(cu.amount || 0), 0);
}
function remainingDue() {
    return Math.max(0, payableTotal() - paidSum()).toFixed(2);
}

function hasAnotherCashPayment(currentRow = null) {
    return cart.value.some((item) => item !== currentRow && item.method?.id === 'cash' && parseFloat(item.amount || 0) > 0);
}
function availablePaymentMethods(row) {
    return hasAnotherCashPayment(row) ? banks.value : paymentMethods.value;
}
function onChangeBank(row, val) {
    if (val?.id === 'cash' && hasAnotherCashPayment(row)) {
        toast.error('Only one cash row is allowed');
        row.method = banks.value[0] ?? cashOption();
        return;
    }
    row.method = val ? { ...val } : cashOption();
    if (row.method.id === 'cash') row.last_digit = '';
    ensureEmptyRow(row);
}
function clampRow(currentRow) {
    const total = payableTotal();
    const otherPaid = cart.value.filter((item) => item !== currentRow).reduce((pr, cu) => pr + parseFloat(cu.amount || 0), 0);
    const maxAllowed = total - otherPaid;
    if (parseFloat(currentRow.amount || 0) > maxAllowed) {
        currentRow.amount = maxAllowed > 0 ? maxAllowed.toFixed(2) : 0;
    }
}
function ensureEmptyRow(currentRow) {
    clampRow(currentRow);
    const currentIsEmpty = !currentRow.amount || parseFloat(currentRow.amount) <= 0;
    if (currentIsEmpty) {
        cart.value = cart.value.filter((item) => item === currentRow || (item.amount && parseFloat(item.amount) > 0));
    }
    const hasEmptyRow = cart.value.some((item) => !item.amount || parseFloat(item.amount) <= 0);
    if (!hasEmptyRow) cart.value.push(emptyRow());
}
function removeRow(sl) {
    cart.value.splice(sl, 1);
    if (!cart.value.some((item) => !item.amount || parseFloat(item.amount) <= 0)) {
        cart.value.push(emptyRow());
    }
}

function resetForm() {
    cart.value = [emptyRow()];
    note.value = '';
    date.value = todayStr();
    done.value = false;
}

function close() {
    emit('update:modelValue', false);
}

// Records one Receive/Payment (whichever `direction` resolves to) per non-empty
// cash/bank row — the exact same endpoints the standalone Receive/Payment pages use —
// so a split payment becomes one record per method, all against the same party.
async function submit() {
    const rows = cart.value.filter((row) => row.amount && parseFloat(row.amount) > 0);
    if (rows.length === 0) {
        toast.error('Enter an amount');
        return;
    }
    submitting.value = true;
    try {
        for (const row of rows) {
            const invRes = await axios.post(invoiceUrl.value, { type: props.mode });
            const payload = {
                invoice: invRes.data.invoice,
                date: date.value,
                type: props.mode,
                [`${props.mode}_id`]: props.entity.id,
                payment_method: row.method?.id === 'cash' ? 'cash' : 'bank',
                amount: row.amount,
                note: note.value,
            };
            if (row.method?.id !== 'cash') payload.bank_id = row.method.id;
            await axios.post(submitUrl.value, payload);
        }
        toast.success(`${actionLabel.value === 'Collect' ? 'Payment collected' : 'Payment made'} successfully`);
        done.value = true;
        emit('paid');
        cart.value = [emptyRow()];
        note.value = '';
        await fetchDue();
    } catch (err) {
        const r = err.response?.data;
        if (err.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    } finally {
        submitting.value = false;
    }
}

watch(
    () => [props.modelValue, props.entity?.id],
    ([open, id]) => {
        if (open && id) {
            resetForm();
            fetchDue();
        }
    },
    { immediate: true }
);

getBanks();
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-[70%] min-w-[320px] max-w-md flex-col bg-slate-50 shadow-2xl animate-slide-in">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-cash-coin"></i> {{ actionLabel }} — {{ entity.name }}</h2>
                    <button type="button" @click="close" class="text-slate-400 hover:text-slate-600">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="flex-1 space-y-3 overflow-y-auto p-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ modeLabel }}</label>
                        <input type="text" readonly :value="`${entity.name}${entity.code ? ' - ' + entity.code : ''}`" class="w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Previous Due</label>
                        <input type="text" readonly :value="previousDueLoading ? 'Loading...' : previousDue.toFixed(2)" class="w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                        <input type="date" v-model="date" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    </div>

                    <div v-if="done" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">
                        <i class="bi bi-check-circle-fill"></i> Payment recorded for {{ entity.name }}.
                    </div>
                    <template v-else>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Payment</label>
                            <div class="space-y-2">
                                <div v-for="(row, index) in cart" :key="index" class="flex flex-wrap items-center gap-2 rounded-md border border-slate-200 bg-white p-2 text-xs">
                                    <div class="min-w-[120px] flex-1">
                                        <SearchSelect :options="availablePaymentMethods(row)" v-model="row.method" label="display_name" placeholder="Select" @update:model-value="onChangeBank(row, $event)" />
                                    </div>
                                    <input
                                        v-if="row.method?.id !== 'cash'"
                                        type="text"
                                        v-model="row.last_digit"
                                        placeholder="Last digit"
                                        class="max-w-[40%] min-w-0 flex-1 rounded border border-slate-300 bg-white px-2 py-1"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        step="any"
                                        v-model="row.amount"
                                        @input="ensureEmptyRow(row)"
                                        placeholder="Amount"
                                        class="max-w-[40%] min-w-0 flex-1 rounded border border-slate-300 bg-white px-2 py-1 font-medium"
                                    />
                                    <i @click="removeRow(index)" class="bi bi-trash3 cursor-pointer text-red-500"></i>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Paid</label>
                                <input type="number" :value="paidSum().toFixed(2)" disabled class="w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-1 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Remaining</label>
                                <input type="number" :value="remainingDue()" readonly class="w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-1 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                            <input type="text" v-model="note" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3">
                    <button type="button" @click="close" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                    <button
                        v-if="!done"
                        type="button"
                        :disabled="submitting || paidSum() <= 0"
                        @click="submit"
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                    >
                        <i class="bi bi-check-lg"></i> {{ submitting ? 'Saving...' : actionLabel }}
                    </button>
                </div>
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
