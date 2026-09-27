<script setup>
import { ref } from 'vue';

// A block of commands to paste somewhere else (router terminal, Linux shell), with a copy button.
defineProps({
    code: { type: String, required: true },
    label: { type: String, default: '' }, // where to paste it, e.g. "MikroTik terminal"
});
const copied = ref(false);

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // plain http (not localhost) has no clipboard API
        const ta = Object.assign(document.createElement('textarea'), { value: text });
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-800 bg-slate-950">
        <div class="flex items-center justify-between border-b border-slate-800 px-2 py-1 text-[11px] text-slate-400">
            <span><i class="bi bi-terminal"></i> {{ label }}</span>
            <button type="button" class="rounded px-1.5 hover:bg-slate-800 hover:text-slate-100" @click="copy(code)"><i class="bi" :class="copied ? 'bi-check2 text-emerald-400' : 'bi-clipboard'"></i> {{ copied ? 'Copied' : 'Copy' }}</button>
        </div>
        <pre class="overflow-x-auto whitespace-pre px-3 py-2 font-mono text-xs leading-relaxed text-slate-100">{{ code }}</pre>
    </div>
</template>
