<script setup>
import { ref } from 'vue';
import { withDecimal } from '../../lib/numberToWords';
import { printDocument } from '../../lib/print';

const props = defineProps({
    transaction: { type: Object, required: true },
    username: { type: String, default: '' },
    company: { type: Object, default: () => ({}) },
});

const invoiceContent = ref(null);
const label = props.transaction.type === 'income' ? 'Income' : 'Expense';

function print() {
    printDocument(`${label} Invoice`, invoiceContent.value.innerHTML, props.company, '', null, { hideTitle: true });
}

defineExpose({ print });
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div ref="invoiceContent">
            <div class="flex items-center text-center">
                <div class="flex-1 border-b border-slate-800"></div>
                <div class="px-4 text-lg font-bold">{{ label }} Invoice</div>
                <div class="flex-1 border-b border-slate-800"></div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-4 rounded-md border-2 border-slate-800 p-3">
                <div>
                    <p class="text-sm"><strong>Invoice No:</strong> {{ transaction.invoice }}</p>
                    <p class="text-sm"><strong>Date:</strong> {{ transaction.date }}</p>
                    <p class="text-sm"><strong>Added By:</strong> {{ username }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm"><strong>Account:</strong> {{ transaction.account?.name }}</p>
                    <p class="text-sm"><strong>Type:</strong> {{ label }}</p>
                </div>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full border-collapse border border-slate-300 text-sm">
                    <tbody>
                        <tr>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold">Description</td>
                            <td class="border border-slate-300 px-2 py-1.5">{{ transaction.note || '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold">Amount</td>
                            <td class="border border-slate-300 px-2 py-1.5 text-right font-bold">{{ transaction.amount }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-sm"><strong>In Word:</strong> {{ withDecimal(transaction.amount) }}</p>
            </div>

            <div class="mt-10 flex justify-between text-sm">
                <span class="[text-decoration:overline]">Received/Paid By</span>
                <span class="[text-decoration:overline]">Authorized Signature</span>
            </div>
        </div>
    </div>
</template>
