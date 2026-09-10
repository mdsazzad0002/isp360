<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import debounce from 'lodash/debounce';
import { translateMenuGroups } from '../lib/menu';

const page = usePage();
const { t, te } = useI18n();

const MENU_LIMIT = 30;

const open = ref(false);
const query = ref('');
const loading = ref(false);
const backendResults = ref([]);
const highlightIndex = ref(-1);

const searchInput = ref(null);

const menuGroups = computed(() => translateMenuGroups(page.props.menuGroups ?? [], t, te));
const menuItems = computed(() =>
    menuGroups.value.flatMap((group) =>
        group.items
            .filter((item) => item.uri)
            .map((item) => ({
                type: 'Menu',
                icon: group.icon || 'bi-link-45deg',
                id: item.uri,
                title: item.label,
                subtitle: group.label,
                url: item.uri,
            }))
    )
);

const filteredMenuItems = computed(() => {
    const term = query.value.trim().toLowerCase();
    const items = term ? menuItems.value.filter((item) => item.title.toLowerCase().includes(term)) : menuItems.value;
    return items.slice(0, MENU_LIMIT);
});

const groups = computed(() => {
    const list = [{ type: 'Menu', items: filteredMenuItems.value }];
    const order = ['Customer', 'Product', 'Supplier'];
    order.forEach((type) => {
        const items = backendResults.value.filter((item) => item.type === type);
        if (items.length) list.push({ type, items });
    });
    return list.filter((group) => group.items.length > 0);
});

const allItems = computed(() => groups.value.flatMap((group) => group.items));

function openModal() {
    open.value = true;
    query.value = '';
    backendResults.value = [];
    highlightIndex.value = -1;
    document.body.style.overflow = 'hidden';
    nextTick(() => searchInput.value && searchInput.value.focus());
}

function closeModal() {
    open.value = false;
    document.body.style.overflow = '';
}

function mapResults(data) {
    const customers = (data.customers || []).map((item) => ({
        type: 'Customer',
        icon: 'bi-person-badge',
        id: item.id,
        title: item.name,
        subtitle: [item.phone, item.code].filter(Boolean).join(' · '),
        url: '/customer',
    }));
    const products = (data.products || []).map((item) => ({
        type: 'Product',
        icon: 'bi-box-seam',
        id: item.id,
        title: item.name,
        subtitle: item.code,
        url: '/product',
    }));
    const suppliers = (data.suppliers || []).map((item) => ({
        type: 'Supplier',
        icon: 'bi-truck',
        id: item.id,
        title: item.name,
        subtitle: [item.phone, item.code].filter(Boolean).join(' · '),
        url: '/supplier',
    }));
    return [...customers, ...products, ...suppliers];
}

const debouncedSearch = debounce(async () => {
    const term = query.value.trim();
    if (term.length < 2) {
        backendResults.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    const res = await axios.get('/global-search', { params: { q: term } });
    backendResults.value = mapResults(res.data);
    loading.value = false;
}, 300);

function onSearchInput() {
    highlightIndex.value = -1;
    debouncedSearch();
}

function goTo(item) {
    closeModal();
    router.visit(item.url);
}

function onArrowDown() {
    if (highlightIndex.value < allItems.value.length - 1) highlightIndex.value++;
}
function onArrowUp() {
    if (highlightIndex.value > 0) highlightIndex.value--;
}
function onEnter() {
    if (highlightIndex.value > -1 && allItems.value[highlightIndex.value]) {
        goTo(allItems.value[highlightIndex.value]);
    }
}

function flatIndex(groupIdx, itemIdx) {
    let idx = 0;
    for (let g = 0; g < groupIdx; g++) idx += groups.value[g].items.length;
    return idx + itemIdx;
}

function handleGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        open.value ? closeModal() : openModal();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <button
        type="button"
        class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-full border border-white/15 bg-white/10 text-white/80 transition hover:bg-white/15 hover:text-white lg:w-full lg:max-w-md lg:justify-start lg:gap-2 lg:px-3.5"
        :title="t('topbar.search_title')"
        @click="openModal"
    >
        <i class="bi bi-search text-sm"></i>
        <span class="hidden flex-1 text-left text-sm lg:inline">{{ t('topbar.search_placeholder') }}</span>
        <kbd class="hidden rounded border border-white/25 bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold lg:inline">Ctrl K</kbd>
    </button>

    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[3000] flex items-start justify-center bg-slate-900/50 px-4 pt-20" @click.self="closeModal">
            <div class="flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
                <div class="flex items-center gap-2.5 border-b border-slate-200 px-4 py-3">
                    <i class="bi bi-search text-lg text-slate-400"></i>
                    <input
                        ref="searchInput"
                        type="search"
                        autocomplete="off"
                        v-model="query"
                        :placeholder="t('topbar.search_placeholder')"
                        class="w-full flex-1 border-none bg-transparent text-base text-slate-800 outline-none placeholder:text-slate-400"
                        @input="onSearchInput"
                        @keydown.esc="closeModal"
                        @keydown.down.prevent="onArrowDown"
                        @keydown.up.prevent="onArrowUp"
                        @keydown.enter.prevent="onEnter"
                    />
                    <span v-if="loading" class="text-xs text-slate-400">{{ t('topbar.searching') }}</span>
                    <button type="button" class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-400 hover:bg-slate-50" @click="closeModal">
                        ESC
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    <div v-if="allItems.length === 0" class="px-3 py-8 text-center text-sm text-slate-500">{{ t('topbar.no_results', { term: query }) }}</div>
                    <div v-for="(group, gIdx) in groups" :key="group.type" class="mb-2 last:mb-0">
                        <div class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ group.type }}</div>
                        <div
                            v-for="(item, iIdx) in group.items"
                            :key="item.type + '-' + item.id"
                            class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition"
                            :class="flatIndex(gIdx, iIdx) === highlightIndex ? 'bg-brand-500 text-white' : 'text-slate-700 hover:bg-slate-50'"
                            @click="goTo(item)"
                            @mouseenter="highlightIndex = flatIndex(gIdx, iIdx)"
                        >
                            <i class="bi shrink-0 text-base" :class="[item.icon, flatIndex(gIdx, iIdx) === highlightIndex ? 'text-white' : 'text-brand-500']"></i>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ item.title }}</span>
                                <span class="block truncate text-xs" :class="flatIndex(gIdx, iIdx) === highlightIndex ? 'text-white/80' : 'text-slate-400'">
                                    {{ item.subtitle }}
                                </span>
                            </span>
                            <i class="bi bi-arrow-return-left shrink-0 text-xs" :class="flatIndex(gIdx, iIdx) === highlightIndex ? 'text-white/70' : 'text-slate-300'"></i>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-4 border-t border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-400">
                    <span class="flex items-center gap-1"><kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5">↑</kbd><kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5">↓</kbd> {{ t('topbar.navigate') }}</span>
                    <span class="flex items-center gap-1"><kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5">↵</kbd> {{ t('topbar.select') }}</span>
                    <span class="flex items-center gap-1"><kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5">esc</kbd> {{ t('topbar.close') }}</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
