<script setup>
defineProps({
    loading: Boolean,
    loaded: Boolean,
    ledgers: { type: Array, default: () => [] },
    previousBalance: { type: [Number, String], default: 0 },
    columnLabels: { type: Object, required: true },
    balanceClass: { type: Function, required: true },
    sumField: { type: Function, required: true },
    lastBalance: { type: Function, required: true },
    dateFrom: { type: String, default: '' },
    dateTo: { type: String, default: '' },
    quickRanges: { type: Array, default: () => [] },
    activeRange: { type: [String, null], default: null },
});

defineEmits(['update:dateFrom', 'update:dateTo', 'date-input', 'quick-range', 'show']);
</script>

<template>
    <div class="mb-3 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
            <input
                type="date"
                :value="dateFrom"
                @input="$emit('update:dateFrom', $event.target.value), $emit('date-input')"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
            />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
            <input
                type="date"
                :value="dateTo"
                @input="$emit('update:dateTo', $event.target.value), $emit('date-input')"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
            />
        </div>
        <div class="flex gap-1">
            <button
                v-for="range in quickRanges"
                :key="range.key"
                type="button"
                @click="$emit('quick-range', range)"
                :class="[
                    'rounded-md px-3 py-1.5 text-xs font-medium transition',
                    activeRange === range.key ? 'bg-brand-500 text-white' : 'border border-slate-300 text-slate-600 hover:bg-slate-50',
                ]"
            >
                {{ range.label }}
            </button>
        </div>
        <button type="button" @click="$emit('show')" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-brand-600">Show</button>
    </div>

    <div v-if="loading" class="flex h-40 items-center justify-center text-sm text-slate-400">Loading…</div>
    <div v-else-if="loaded" class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full border border-collapse border-slate-200 text-sm">
                <thead>
                    <tr class="bg-slate-50 text-start text-slate-600">
                        <th class="border border-slate-200 px-2 py-2 font-medium">Date</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">Description</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.bill }}</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.paid }}</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.due }}</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.cash_payment }}</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.cash_receive }}</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.return_amount }}</th>
                        <th class="border border-slate-200 px-2 py-2 font-medium">{{ columnLabels.balance }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="border border-slate-200 px-2 py-1.5"></td>
                        <td colspan="7" class="border border-slate-200 px-2 py-1.5">Previous Balance</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ previousBalance }}</td>
                    </tr>
                    <tr v-for="(item, index) in ledgers" :key="index">
                        <td class="border border-slate-200 px-2 py-1.5">{{ item.date }}</td>
                        <td class="border border-slate-200 px-2 py-1.5">{{ item.description }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.bill }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.paid }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.due }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.cash_payment }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.cash_receive }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end">{{ item.return_amount }}</td>
                        <td class="border border-slate-200 px-2 py-1.5 text-end font-medium" :class="balanceClass(item.balance)">{{ parseFloat(item.balance).toFixed(2) }}</td>
                    </tr>
                    <tr v-if="ledgers.length === 0">
                        <td colspan="9" class="border border-slate-200 px-2 py-6 text-center text-slate-400">No records found</td>
                    </tr>
                    <tr v-else class="bg-slate-50 font-semibold">
                        <td colspan="2" class="border border-slate-200 px-2 py-2 text-center">Total</td>
                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('bill') }}</td>
                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('paid') }}</td>
                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('due') }}</td>
                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('cash_payment') }}</td>
                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('cash_receive') }}</td>
                        <td class="border border-slate-200 px-2 py-2 text-end">{{ sumField('return_amount') }}</td>
                        <td class="border border-slate-200 px-2 py-2 text-end" :class="balanceClass(lastBalance())">{{ lastBalance() }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
