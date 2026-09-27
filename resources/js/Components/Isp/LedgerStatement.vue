<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { money, fmtDate, label } from '../../lib/isp';
import { printStatement } from '../../lib/ispPrint';

const props = defineProps({
    customer: { type: Object, required: true },
    endpoint: { type: String, default: '/isp/get-customer-statement' },
});
const page = usePage();
const dateFrom = ref('');
const dateTo = ref('');
const statement = ref(null);

function load() {
    axios.post(props.endpoint, { id: props.customer.id, dateFrom: dateFrom.value, dateTo: dateTo.value }).then((res) => (statement.value = res.data));
}

function print() {
    printStatement(props.customer, statement.value, page.props.company, dateFrom.value || dateTo.value ? `${fmtDate(dateFrom.value) || 'Start'} – ${fmtDate(dateTo.value) || 'Today'}` : '');
}

onMounted(load);
defineExpose({ load });
</script>

<template>
    <div>
        <div class="mb-2 flex flex-wrap items-end gap-2">
            <div>
                <label class="mb-1 block text-xs text-slate-500">From</label>
                <input v-model="dateFrom" type="date" class="rounded-md border border-slate-300 px-2 py-1 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">To</label>
                <input v-model="dateTo" type="date" class="rounded-md border border-slate-300 px-2 py-1 text-sm" />
            </div>
            <button type="button" class="rounded-md bg-brand-500 px-3 py-1 text-sm text-white" @click="load">Show</button>
            <button v-if="statement" type="button" class="ml-auto rounded-md border border-slate-300 px-3 py-1 text-sm" @click="print"><i class="bi bi-printer"></i> Print statement</button>
        </div>
        <div v-if="statement" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                        <th class="px-2 py-1.5 font-medium">Date</th>
                        <th class="px-2 py-1.5 font-medium">Type</th>
                        <th class="px-2 py-1.5 font-medium">Description</th>
                        <th class="px-2 py-1.5 text-right font-medium">Debit</th>
                        <th class="px-2 py-1.5 text-right font-medium">Credit</th>
                        <th class="px-2 py-1.5 text-right font-medium">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-slate-100 text-slate-500"><td class="px-2 py-1.5" colspan="5">Opening balance</td><td class="px-2 py-1.5 text-right">{{ money(statement.opening) }}</td></tr>
                    <tr v-for="r in statement.rows" :key="r.id" class="border-b border-slate-100">
                        <td class="whitespace-nowrap px-2 py-1.5">{{ fmtDate(r.entry_date) }}</td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-xs text-slate-500">{{ label(r.type) }}</td>
                        <td class="px-2 py-1.5">{{ r.description }}</td>
                        <td class="px-2 py-1.5 text-right">{{ Number(r.debit) ? money(r.debit) : '' }}</td>
                        <td class="px-2 py-1.5 text-right text-emerald-700">{{ Number(r.credit) ? money(r.credit) : '' }}</td>
                        <td class="px-2 py-1.5 text-right font-medium" :class="r.balance > 0 ? 'text-red-600' : 'text-slate-700'">{{ money(r.balance) }}</td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="px-2 py-1.5" colspan="3">Total</td>
                        <td class="px-2 py-1.5 text-right">{{ money(statement.total_debit) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ money(statement.total_credit) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ money(statement.closing) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-1 text-xs text-slate-400">Positive balance = due. Negative balance = advance credit.</p>
        </div>
    </div>
</template>
