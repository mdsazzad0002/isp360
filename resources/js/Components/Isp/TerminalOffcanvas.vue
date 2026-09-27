<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import ConnectionTerminal from './ConnectionTerminal.vue';
import TerminalDocs from './TerminalDocs.vue';

// The connection terminal as a side panel, usable from any page:
//   <TerminalOffcanvas v-model="show" :connections="[conn]" :connection-id="conn.id" @changed="reload" />
// Sits above modals (z 2100) so it can open from the connection panel; below dropdowns (2150).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    connections: { type: Array, default: () => [] },
    connectionId: { type: [Number, null], default: null },
});
const emit = defineEmits(['update:modelValue', 'changed']);

const terminal = ref(null);
const ctx = ref({}); // selected connection's real values, used to fill the docs examples
let docsPref = true;
try {
    docsPref = localStorage.getItem('isp-terminal-docs') !== '0';
} catch {
    /* default: docs open */
}
const showDocs = ref(docsPref);

function toggleDocs() {
    showDocs.value = !showDocs.value;
    try {
        localStorage.setItem('isp-terminal-docs', showDocs.value ? '1' : '0');
    } catch {
        /* preference only */
    }
}
function close() {
    emit('update:modelValue', false);
}
// Commands that can change the sync status or the router: let the page refresh.
function onRan(cmd) {
    if (['verify', 'sync', 'kick', 'diagnose', 'diag'].includes(cmd)) emit('changed');
}
function onKey(e) {
    if (e.key === 'Escape' && props.modelValue) close();
}
watch(
    () => props.modelValue,
    (open) => (open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)),
);
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-[2120] flex justify-end">
            <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
            <div class="relative flex h-full w-full flex-col bg-white shadow-2xl animate-slide-in" :class="showDocs ? 'xl:w-[88%]' : 'lg:w-[70%]'">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-bold text-slate-800"><i class="bi bi-terminal"></i> Connection Terminal</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-md border px-2.5 py-1 text-xs" :class="showDocs ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-600'" @click="toggleDocs">
                            <i class="bi bi-book"></i> Docs
                        </button>
                        <button type="button" class="text-slate-400 hover:text-slate-600" title="Close (Esc)" @click="close"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="flex min-h-0 flex-1">
                    <div class="min-w-0 flex-1 overflow-y-auto p-4">
                        <ConnectionTerminal ref="terminal" :connections="connections" :connection-id="connectionId" height="calc(100vh - 250px)" @ran="onRan" @context="(c) => (ctx = c)" />
                    </div>
                    <aside v-if="showDocs" class="hidden w-[380px] shrink-0 border-s border-slate-200 bg-slate-50 md:block">
                        <TerminalDocs :ctx="ctx" @use="(cmd) => terminal?.setInput(cmd)" @run="(cmd) => terminal?.run(cmd)" @refresh="terminal?.refresh()" />
                    </aside>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
.animate-slide-in {
    animation: slide-in 0.25s ease-out;
}
</style>
