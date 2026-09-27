<script setup>
import { ref, reactive, watch, onMounted } from 'vue';
import axios from 'axios';
import { money, fmtDate, monthStart, today } from '../../lib/isp';

// Reseller ledger with running balance. Balance > 0: the company owes the reseller.
const props = defineProps({
    endpoint: { type: String, required: true },
    resellerId: { type: [Number, String], default: null },
    // admin statement needs a reseller picked first; the reseller portal uses the logged-in one
    requireReseller: { type: Boolean, default: false },
});

const TYPE_LABELS = {
    earning: 'Earning',
    earning_reversed: 'Earning reversed',
    collection: 'Cash collected',
    collection_reversed: 'Collection reversed',
    deposit: 'Deposit',
    withdrawal: 'Withdrawal',
};

const filter = reactive({ dateFrom: monthStart(), dateTo: today() });
const data = ref(null);
const loading = ref(false);

function load() {
    if (props.requireReseller && !props.resellerId) {
        data.value = null;
        return;
    }
    loading.value = true;
    axios.post(props.endpoint, { resellerId: props.resellerId, ...filter })
        .then((res) => (data.value = res.data))
        .finally(() => (loading.value = false));
}
const printPage = () => window.print();
const signed = (v) => (v < 0 ? '−' : '') + money(Math.abs(v || 0));

watch(() => props.resellerId, load);
onMounted(load);
</script>

<template>
    <div class="space-y-3">
        <div v-if="data" class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Earned (all time)</div>
                <div class="text-lg font-semibold text-slate-800">{{ money(data.wallet.earned) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Cash in reseller's hand</div>
                <div class="text-lg font-semibold text-amber-700">{{ money(data.wallet.collected - data.wallet.deposits) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-[11px] uppercase text-slate-400">Withdrawn / pending</div>
                <div class="text-lg font-semibold text-slate-800">{{ money(data.wallet.withdrawn) }} <span class="text-sm font-normal text-amber-700">/ {{ money(data.wallet.pending) }}</span></div>
            </div>
            <div class="rounded-lg border p-3 shadow-sm" :class="data.wallet.balance < 0 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'">
                <div class="text-[11px] uppercase text-slate-500">{{ data.wallet.balance < 0 ? 'Reseller owes company' : 'Company owes reseller' }}</div>
                <div class="text-lg font-semibold" :class="data.wallet.balance < 0 ? 'text-red-600' : 'text-emerald-700'">{{ money(Math.abs(data.wallet.balance)) }}</div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <slot name="filters" />
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                        <input v-model="filter.dateFrom" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="load" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                        <input v-model="filter.dateTo" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm" @change="load" />
                    </div>
                </div>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm print:hidden" @click="printPage"><i class="bi bi-printer"></i> Print</button>
            </div>

            <div v-if="!data" class="py-8 text-center text-sm text-slate-400">{{ loading ? 'Loading…' : 'Select a reseller' }}</div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 font-medium">Ref</th>
                            <th class="px-3 py-2 text-right font-medium">Credit (+)</th>
                            <th class="px-3 py-2 text-right font-medium">Debit (−)</th>
                            <th class="px-3 py-2 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100 bg-slate-50/60">
                            <td class="px-3 py-2" colspan="6">Opening balance</td>
                            <td class="px-3 py-2 text-right font-medium">{{ signed(data.opening) }}</td>
                        </tr>
                        <tr v-for="(row, i) in data.rows" :key="i" class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="whitespace-nowrap px-3 py-2">{{ fmtDate(row.date) }} <span class="text-xs text-slate-400">{{ row.time }}</span></td>
                            <td class="whitespace-nowrap px-3 py-2">{{ TYPE_LABELS[row.type] || row.type }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ row.description }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ row.ref }}</td>
                            <td class="px-3 py-2 text-right text-emerald-700">{{ row.credit ? money(row.credit) : '' }}</td>
                            <td class="px-3 py-2 text-right text-red-600">{{ row.debit ? money(row.debit) : '' }}</td>
                            <td class="px-3 py-2 text-right font-medium" :class="row.balance < 0 ? 'text-red-600' : ''">{{ signed(row.balance) }}</td>
                        </tr>
                        <tr v-if="!data.rows.length"><td colspan="7" class="px-3 py-6 text-center text-slate-400">No entries in this range</td></tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 font-semibold">
                            <td class="px-3 py-2" colspan="4">Closing balance</td>
                            <td class="px-3 py-2 text-right text-emerald-700">{{ money(data.credit) }}</td>
                            <td class="px-3 py-2 text-right text-red-600">{{ money(data.debit) }}</td>
                            <td class="px-3 py-2 text-right" :class="data.closing < 0 ? 'text-red-600' : ''">{{ signed(data.closing) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <p class="mt-2 text-xs text-slate-500">Positive balance: the company owes the reseller. Negative: the reseller holds company money (collected cash not yet deposited).</p>
            </div>
        </div>
    </div>
</template>
