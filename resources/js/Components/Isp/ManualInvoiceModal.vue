<script setup>
import { ref, reactive, computed, watch } from 'vue';
import axios from 'axios';
import Modal from '../Modal.vue';
import CustomerPicker from './CustomerPicker.vue';
import { money, today, useApiError, moneyStep } from '../../lib/isp';
import { useToast } from '../../lib/toast';

// One-off invoice (installation, equipment, service charge...) or edit of a draft.
const props = defineProps({
    show: Boolean,
    customerId: { type: [Number, null], default: null },
    draft: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToast();
const showError = useApiError();

const customer = ref(null);
const connections = ref([]);
const saving = ref(false);
const form = reactive({ id: null, connection_id: '', invoice_date: today(), due_date: today(), discount: 0, notes: '', no_tax: false });
const items = ref([]);

const subtotal = computed(() => items.value.reduce((s, i) => s + Number(i.unit_price || 0) * Number(i.quantity || 0) - Number(i.discount || 0), 0));

function blankItem() {
    return { description: '', unit_price: '', quantity: 1, discount: 0 };
}

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        const d = props.draft;
        Object.assign(form, {
            id: d?.id ?? null,
            connection_id: d?.connection_id ?? '',
            invoice_date: d?.invoice_date ?? today(),
            due_date: d?.due_date ?? today(),
            discount: Number(d?.discount ?? 0),
            notes: d?.notes ?? '',
            // a draft saved without tax keeps that choice
            no_tax: d ? Number(d.tax || 0) === 0 && (d.items || []).every((i) => !i.taxes) : false,
        });
        items.value = d?.items?.length ? d.items.map((i) => ({ description: i.description, unit_price: Number(i.unit_price), quantity: Number(i.quantity), discount: Number(i.discount) })) : [blankItem()];
    }
);

watch(customer, (c) => {
    connections.value = [];
    if (c) axios.post('/isp/get-connections', { customerId: c.id, per_page: 100 }).then((r) => (connections.value = r.data.data));
});

async function save(asDraft) {
    if (!customer.value) return toast.error('Select a customer');
    saving.value = true;
    try {
        const res = await axios.post('/isp/invoice', { ...form, customer_id: customer.value.id, connection_id: form.connection_id || null, items: items.value, as_draft: asDraft });
        toast.success(res.data.message);
        emit('saved', res.data.id);
        emit('close');
    } catch (err) {
        showError(err);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="max-w-3xl" @close="emit('close')">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-base font-semibold text-slate-800">{{ form.id ? 'Edit draft invoice' : 'New manual invoice' }}</h2>
            <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="max-h-[75vh] space-y-3 overflow-y-auto p-4 text-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
                    <CustomerPicker v-model="customer" :preselect-id="draft?.customer_id || customerId" :disabled="!!(draft || customerId)" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Connection <span class="text-slate-400">(optional)</span></label>
                    <select v-model="form.connection_id" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">— Customer level —</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.code }} · {{ c.package?.name }} · {{ c.status }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Invoice date</label>
                    <input v-model="form.invoice_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Due date</label>
                    <input v-model="form.due_date" type="date" :min="form.invoice_date" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                </div>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                        <th class="px-2 py-1.5 font-medium">Description</th>
                        <th class="w-28 px-2 py-1.5 font-medium">Price</th>
                        <th class="w-20 px-2 py-1.5 font-medium">Qty</th>
                        <th class="w-24 px-2 py-1.5 font-medium">Discount</th>
                        <th class="w-24 px-2 py-1.5 text-end font-medium">Total</th>
                        <th class="w-8"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, i) in items" :key="i" class="border-b border-slate-100">
                        <td class="px-1 py-1"><input v-model="item.description" type="text" placeholder="e.g. ONU device, installation" class="w-full rounded border border-slate-300 px-2 py-1" /></td>
                        <td class="px-1 py-1"><input v-model="item.unit_price" type="number" min="0" :step="moneyStep()" class="w-full rounded border border-slate-300 px-2 py-1" /></td>
                        <td class="px-1 py-1"><input v-model="item.quantity" type="number" min="0.01" step="0.01" class="w-full rounded border border-slate-300 px-2 py-1" /></td>
                        <td class="px-1 py-1"><input v-model="item.discount" type="number" min="0" :step="moneyStep()" class="w-full rounded border border-slate-300 px-2 py-1" /></td>
                        <td class="px-2 py-1 text-end">{{ money(Number(item.unit_price || 0) * Number(item.quantity || 0) - Number(item.discount || 0)) }}</td>
                        <td class="px-1 py-1 text-center"><i v-if="items.length > 1" class="bi bi-trash cursor-pointer text-red-500" @click="items.splice(i, 1)"></i></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="text-sm text-brand-600 hover:underline" @click="items.push(blankItem())"><i class="bi bi-plus-circle"></i> Add line</button>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Note</label>
                    <textarea v-model="form.notes" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm"></textarea>
                </div>
                <div class="space-y-1 text-end">
                    <div>Subtotal: <strong>{{ money(subtotal) }}</strong></div>
                    <div class="flex items-center justify-end gap-2">Invoice discount <input v-model="form.discount" type="number" min="0" :step="moneyStep()" class="w-28 rounded border border-slate-300 px-2 py-1 text-end" /></div>
                    <label class="flex items-center justify-end gap-2 text-xs text-slate-500"><input v-model="form.no_tax" type="checkbox" /> No tax on this invoice</label>
                    <div class="text-base">Total: <strong>{{ money(subtotal - Number(form.discount || 0)) }}</strong><span v-if="!form.no_tax" class="text-xs text-slate-400"> · tax at the default rates is worked out on save</span></div>
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
            <button type="button" :disabled="saving" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm disabled:opacity-50" @click="save(true)">Save as draft</button>
            <button type="button" :disabled="saving" class="rounded-md bg-brand-500 px-4 py-1.5 text-sm text-white hover:bg-brand-600 disabled:opacity-50" @click="save(false)">Save &amp; issue</button>
        </div>
    </Modal>
</template>
