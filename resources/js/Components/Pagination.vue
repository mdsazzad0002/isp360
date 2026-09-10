<script setup>
import { computed } from 'vue';
import { buildPageNumbers } from '../lib/pagination';

const props = defineProps({
    page: { type: Number, required: true },
    totalPages: { type: Number, required: true },
});
const emit = defineEmits(['change']);

const pages = computed(() => buildPageNumbers(props.page, props.totalPages));

function go(p) {
    if (p === '...') return;
    if (p < 1 || p > props.totalPages) return;
    emit('change', p);
}
</script>

<template>
    <div class="mt-2 flex flex-wrap gap-1">
        <button type="button" @click="go(page - 1)" :disabled="page === 1" class="cursor-pointer rounded border border-slate-300 px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-40">Prev</button>
        <button
            v-for="p in pages"
            :key="p"
            type="button"
            @click="go(p)"
            class="cursor-pointer rounded border px-2.5 py-1 text-xs"
            :class="p === page ? 'border-brand-500 bg-brand-500 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
        >
            {{ p }}
        </button>
        <button type="button" @click="go(page + 1)" :disabled="page === totalPages" class="cursor-pointer rounded border border-slate-300 px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-40">Next</button>
    </div>
</template>
