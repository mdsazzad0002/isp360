<script setup>
import { computed } from 'vue';
import { fmtDate } from '../../lib/isp';

// Router sync status of a connection, shown next to (not instead of) its billing status.
const props = defineProps({
    connection: { type: Object, required: true },
    showNote: { type: Boolean, default: false },
});

const STYLES = {
    synced: ['Synced', 'bi-check-circle-fill', 'border-emerald-200 bg-emerald-50 text-emerald-700'],
    pending: ['Sync pending', 'bi-hourglass-split', 'border-sky-200 bg-sky-50 text-sky-700'],
    failed: ['Sync failed', 'bi-x-octagon-fill', 'border-red-200 bg-red-50 text-red-700'],
    mismatch: ['Router mismatch', 'bi-exclamation-triangle-fill', 'border-amber-300 bg-amber-50 text-amber-800'],
    not_managed: ['Not managed', 'bi-dash-circle', 'border-slate-300 bg-slate-100 text-slate-600'],
};
const status = computed(() => props.connection.network_sync_status || 'pending');
const style = computed(() => STYLES[status.value] || STYLES.pending);
// Active in billing but not confirmed on the router: the case staff must notice.
const alarming = computed(() => props.connection.status === 'active' && ['failed', 'mismatch', 'pending'].includes(status.value));
const note = computed(() => props.connection.network_sync_error && status.value === 'failed' ? props.connection.network_sync_error : props.connection.network_sync_note);
const title = computed(() => {
    const parts = [style.value[0]];
    if (note.value) parts.push(note.value);
    if (props.connection.network_synced_at) parts.push(`Last pushed ${fmtDate(props.connection.network_synced_at)} ${String(props.connection.network_synced_at).slice(11, 16)}`);
    if (props.connection.network_checked_at) parts.push(`Last verified ${fmtDate(props.connection.network_checked_at)} ${String(props.connection.network_checked_at).slice(11, 16)}`);
    return parts.join('\n');
});
</script>

<template>
    <span class="inline-flex flex-col items-start gap-0.5">
        <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="[style[2], alarming ? 'ring-1 ring-red-300' : '']" :title="title">
            <i class="bi text-[10px]" :class="style[1]"></i>{{ style[0] }}
        </span>
        <span v-if="showNote && note" class="max-w-xs text-[11px] leading-tight" :class="status === 'not_managed' ? 'text-slate-500' : 'text-red-600'">{{ note }}</span>
    </span>
</template>
