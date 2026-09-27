<script setup>
import { computed } from 'vue';
import { STATUS_CLASSES, label } from '../../lib/isp';

const props = defineProps({ status: { type: String, default: '' } });
const cls = computed(() => STATUS_CLASSES[props.status] || 'bg-slate-100 text-slate-600 border-slate-300');
</script>

<template>
    <span class="inline-flex items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="cls">{{ label(status) }}</span>
</template>
