<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { useToast } from '../lib/toast';

const page = usePage();
const toast = useToast();
const { t } = useI18n();

const root = ref(null);
const open = ref(false);
const branches = ref([]);
const loading = ref(false);
const switching = ref(false);

const company = computed(() => page.props.company ?? {});
const currentBranch = computed(() => page.props.currentBranch ?? null);
const canSwitch = computed(() => page.props.canSwitchBranch === true);
const showSwitcher = computed(() => company.value.multi_branch_status === 'active' && canSwitch.value);

function handleClickOutside(e) {
    if (root.value && !root.value.contains(e.target)) {
        open.value = false;
        document.removeEventListener('click', handleClickOutside);
    }
}

async function toggleOpen() {
    open.value = !open.value;
    if (open.value) {
        document.addEventListener('click', handleClickOutside);
        if (branches.value.length === 0) {
            loading.value = true;
            try {
                const res = await axios.get('/get-branch', { params: { forSwitch: 'yes' } });
                branches.value = res.data;
            } finally {
                loading.value = false;
            }
        }
    } else {
        document.removeEventListener('click', handleClickOutside);
    }
}

async function selectBranch(branch) {
    if (switching.value || branch.id === currentBranch.value?.id) {
        open.value = false;
        return;
    }
    switching.value = true;
    try {
        const res = await axios.post('/switch-branch', { branch_id: branch.id });
        if (res.data.status) {
            toast.success(res.data.message);
            open.value = false;
            router.reload();
        } else {
            toast.error(res.data.message);
        }
    } finally {
        switching.value = false;
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div v-if="showSwitcher" ref="root" class="relative">
        <button
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-white/90 transition hover:bg-white/10 hover:text-white"
            @click="toggleOpen"
        >
            <i class="bi bi-diagram-3 text-base"></i>
            <span class="hidden max-w-[9rem] truncate sm:inline">{{ currentBranch?.name || t('topbar.select_branch') }}</span>
            <i class="bi bi-chevron-down text-xs transition-transform" :class="open ? 'rotate-180' : ''"></i>
        </button>

        <div
            v-show="open"
            class="absolute end-0 top-full z-[2000] mt-2 max-h-72 w-64 overflow-y-auto rounded-md border border-slate-200 bg-white p-1 shadow-xl"
        >
            <div class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('topbar.switch_branch') }}</div>
            <div v-if="loading" class="px-3 py-2 text-center text-xs text-slate-500">{{ t('topbar.loading') }}</div>
            <template v-else>
                <div v-if="branches.length === 0" class="px-3 py-2 text-center text-xs text-slate-500">{{ t('topbar.no_branches') }}</div>
                <button
                    v-for="branch in branches"
                    :key="branch.id"
                    type="button"
                    class="flex w-full items-center gap-2.5 rounded px-3 py-2 text-start text-sm transition hover:bg-slate-50"
                    :class="branch.id === currentBranch?.id ? 'bg-brand-50 font-medium text-brand-600' : 'text-slate-700'"
                    :disabled="switching"
                    @click="selectBranch(branch)"
                >
                    <i class="bi shrink-0" :class="branch.id === currentBranch?.id ? 'bi-check-circle-fill text-brand-500' : 'bi-circle text-slate-300'"></i>
                    <span class="truncate">{{ branch.name }}</span>
                </button>
            </template>
        </div>
    </div>
</template>
