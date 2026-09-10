<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useToast } from '../lib/toast';

const toast = useToast();
const page = usePage();
const impersonating = computed(() => !!page.props.impersonating);

const root = ref(null);
const open = ref(false);
const loading = ref(false);
const notifications = ref([]);
const processingId = ref(null);
let pollTimer = null;

const typeLabels = {
    stock_transfer: 'Transfer',
    low_stock: 'Low Stock',
};

function load() {
    loading.value = true;
    axios
        .post('/get-notifications')
        .then((res) => {
            notifications.value = res.data;
        })
        .finally(() => {
            loading.value = false;
        });
}

function handleClickOutside(e) {
    if (root.value && !root.value.contains(e.target)) {
        open.value = false;
        document.removeEventListener('click', handleClickOutside);
    }
}

function toggleOpen() {
    open.value = !open.value;
    if (open.value) {
        document.addEventListener('click', handleClickOutside);
        load();
    } else {
        document.removeEventListener('click', handleClickOutside);
    }
}

function openNotification(row) {
    open.value = false;
    router.visit(row.link);
}

function viewAll() {
    open.value = false;
    router.visit('/notifications');
}

async function receiveTransfer(row) {
    processingId.value = row.id;
    try {
        const res = await axios.post('/receive-stock-transfer', { id: row.id });
        toast.success(res.data.message);
        load();
    } catch (err) {
        toast.error(err.response?.data?.message || 'Something went wrong');
    } finally {
        processingId.value = null;
    }
}

onMounted(() => {
    load();
    // Keeps the badge fresh without requiring the panel to be opened —
    // one small aggregate query, cheap enough to poll.
    pollTimer = setInterval(load, 60000);
});

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative flex h-9 w-9 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/10 hover:text-white"
            @click="toggleOpen"
            title="Notifications"
        >
            <i class="bi bi-bell text-lg"></i>
            <span
                v-if="notifications.length > 0"
                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white"
            >
                {{ notifications.length > 9 ? '9+' : notifications.length }}
            </span>
        </button>

        <div
            v-show="open"
            :class="[
                impersonating ? 'top-[88px]' : 'top-14',
                'fixed inset-x-2 z-[2000] max-h-96 overflow-y-auto rounded-md border border-slate-200 bg-white p-1 shadow-xl',
                'sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-80',
            ]"
        >
            <div class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Notifications</div>
            <div v-if="loading" class="px-3 py-4 text-center text-xs text-slate-500">Loading...</div>
            <template v-else>
                <div v-if="notifications.length === 0" class="px-3 py-4 text-center text-xs text-slate-500">Nothing to show</div>
                <div
                    v-for="row in notifications.slice(0, 6)"
                    :key="row.type + '-' + row.id"
                    class="rounded px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                >
                    <div class="flex items-start justify-between gap-2">
                        <button type="button" class="min-w-0 flex-1 text-left" @click="openNotification(row)">
                            <span class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ typeLabels[row.type] ?? row.type }}</span>
                            <span class="block truncate font-medium">{{ row.title }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ row.subtitle }}</span>
                        </button>
                        <button
                            v-if="row.type === 'stock_transfer'"
                            type="button"
                            :disabled="processingId === row.id"
                            @click="receiveTransfer(row)"
                            class="shrink-0 rounded-md bg-emerald-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            Receive
                        </button>
                    </div>
                </div>
                <div class="mt-1 border-t border-slate-100 px-2 py-2 text-center">
                    <button type="button" @click="viewAll" class="text-xs font-medium text-brand-600 hover:underline">View all notifications</button>
                </div>
            </template>
        </div>
    </div>
</template>
