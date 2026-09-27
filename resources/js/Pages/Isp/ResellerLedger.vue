<script setup>
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import ResellerStatement from '../../Components/Isp/ResellerStatement.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({
    resellers: { type: Array, default: () => [] },
    resellerId: { type: Number, default: null },
});
const selected = ref(props.resellerId || (props.resellers.length === 1 ? props.resellers[0].id : ''));
</script>

<template>
    <div class="p-4">
        <h1 class="mb-3 text-base font-semibold text-slate-800">Reseller Ledger</h1>
        <ResellerStatement endpoint="/isp/get-reseller-ledger" require-reseller :reseller-id="selected || null">
            <template #filters>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Reseller</label>
                    <select v-model="selected" class="w-60 rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="">— Select reseller —</option>
                        <option v-for="r in resellers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.code }})</option>
                    </select>
                </div>
            </template>
        </ResellerStatement>
    </div>
</template>
