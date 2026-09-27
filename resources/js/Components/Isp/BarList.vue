<script setup>
import { computed } from 'vue';
import { money } from '../../lib/isp';

// Single-series horizontal bar list (magnitude by category). Values labelled in text ink.
const props = defineProps({
    rows: { type: Array, default: () => [] }, // [{ label, value }]
    isMoney: { type: Boolean, default: false },
    empty: { type: String, default: 'No data yet' },
});
const max = computed(() => Math.max(1, ...props.rows.map((r) => Number(r.value || 0))));
const fmt = (v) => (props.isMoney ? money(v) : Number(v || 0).toLocaleString());
</script>

<template>
    <div class="space-y-2">
        <div v-for="r in rows" :key="r.label" class="text-xs" :title="`${r.label}: ${fmt(r.value)}`">
            <div class="mb-0.5 flex justify-between gap-2 text-slate-600"><span class="truncate">{{ r.label }}</span><span class="font-medium text-slate-800">{{ fmt(r.value) }}</span></div>
            <div class="h-2 rounded bg-slate-100"><div class="bar h-2 rounded" :style="{ width: (Number(r.value || 0) / max) * 100 + '%' }"></div></div>
        </div>
        <div v-if="!rows.length" class="py-4 text-center text-xs text-slate-400">{{ empty }}</div>
    </div>
</template>

<style scoped>
.bar { background: #2a78d6; min-width: 2px; }
:global(.dark) .bar { background: #3987e5; }
</style>
