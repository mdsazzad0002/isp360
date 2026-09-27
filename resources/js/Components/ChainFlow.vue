<script setup>
defineProps({
    nodes: { type: Array, required: true },
});
const emit = defineEmits(['info']);
</script>

<template>
    <div v-if="nodes.length === 0" class="py-10 text-center text-sm text-slate-400">Not Found Data</div>
    <div v-else class="flex flex-col items-center">
        <template v-for="(node, idx) in nodes" :key="node.key">
            <div
                class="flex w-full max-w-md items-center justify-between rounded-lg border px-4 py-2.5 shadow-sm"
                :class="{
                    'border-slate-300 bg-slate-50': node.sign === 'base',
                    'border-emerald-300 bg-emerald-50': node.sign === '+',
                    'border-rose-300 bg-rose-50': node.sign === '-',
                    'border-brand-400 bg-brand-50': node.sign === 'result',
                }"
            >
                <div class="flex items-start gap-2">
                    <span
                        v-if="node.sign === '+' || node.sign === '-'"
                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                        :class="node.sign === '+' ? 'bg-emerald-500' : 'bg-rose-500'"
                        >{{ node.sign }}</span
                    >
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold" :class="node.sign === 'result' ? 'text-brand-700' : 'text-slate-700'">{{ node.label }}</span>
                            <button type="button" @click="emit('info', node)" title="Details" class="text-slate-400 hover:text-brand-500">
                                <i class="bi bi-info-circle"></i>
                            </button>
                        </div>
                        <p v-if="node.desc" class="mt-0.5 text-[11px] leading-snug text-slate-500">{{ node.desc }}</p>
                    </div>
                </div>
                <span class="shrink-0 ps-2 text-sm font-bold" :class="node.sign === 'result' ? 'text-brand-700' : 'text-slate-800'">{{ node.amount }}</span>
            </div>
            <div v-if="idx < nodes.length - 1" class="flex h-6 items-center text-slate-300">
                <i class="bi bi-arrow-down text-lg"></i>
            </div>
        </template>
    </div>
</template>
