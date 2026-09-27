<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ToastStack from '../Components/ToastStack.vue';
import GlobalSearch from '../Components/GlobalSearch.vue';
import BranchSwitcher from '../Components/BranchSwitcher.vue';
import NotificationBell from '../Components/NotificationBell.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import { useToast } from '../lib/toast';
import { isDarkMode, toggleTheme, initTheme, watchSystemTheme } from '../lib/theme';
import { translateMenuGroups } from '../lib/menu';

const page = usePage();
const toast = useToast();
const { t, te } = useI18n();
const darkMode = ref(isDarkMode());
let unwatchSystemTheme;

function onToggleTheme() {
    darkMode.value = toggleTheme();
}
const footerCreditChars = String.fromCharCode(68, 101, 118, 101, 108, 111, 112, 101, 100, 32, 98, 121, 32, 66, 97, 110, 103, 108, 97, 100, 101, 115, 104, 32, 83, 111, 102, 116, 119, 97, 114, 101, 32, 84, 101, 99, 104, 110, 111, 108, 111, 103, 121).split('');
const footerCreditUrl = String.fromCharCode(104, 116, 116, 112, 115, 58, 47, 47, 98, 100, 115, 111, 102, 116, 116, 101, 99, 104, 110, 111, 108, 111, 103, 121, 46, 99, 111, 109);

const DESKTOP_BREAKPOINT = 1024;
const isDesktop = () => typeof window !== 'undefined' && window.innerWidth >= DESKTOP_BREAKPOINT;

const sidebarOpen = ref(isDesktop());
const menuSearch = ref('');
const openGroups = ref(new Set());
const menuSearchInput = ref(null);

function handleResize() {
    sidebarOpen.value = isDesktop();
}

function openLicensePanel() {
    window.SUBandLWidget?.open('update');
}

onMounted(() => {
    window.addEventListener('resize', handleResize);
    darkMode.value = initTheme();
    unwatchSystemTheme = watchSystemTheme((isDark) => (darkMode.value = isDark));
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', handleResize);
    unwatchSystemTheme?.();
});

const auth = computed(() => page.props.auth?.user ?? null);
const impersonating = computed(() => !!page.props.impersonating);
const company = computed(() => page.props.company ?? {});
const menuGroups = computed(() => translateMenuGroups(page.props.menuGroups ?? [], t, te));
const currentPath = computed(() => {
    const url = page.url ?? (typeof window !== 'undefined' ? window.location.pathname : '');
    return url.split('?')[0].split('#')[0];
});

const pageTitle = computed(() => {
    if (isDashboard()) return t('nav.dashboard');
    for (const group of menuGroups.value) {
        const item = group.items.find((i) => isActive(i.match));
        if (item) return item.label;
    }
    return page.component ?? '';
});

const allMenuMatches = computed(() => menuGroups.value.flatMap((group) => group.items.flatMap((item) => (Array.isArray(item.match) ? item.match : [item.match]))));

// A path can exactly match one item's target while also being a prefix match
// for a sibling item (e.g. /profitLoss/flow starts with /profitLoss). When an
// exact match exists among the registered menu items, only that exact match
// should count as active — prefix matching is reserved for detail/child
// routes that have no menu entry of their own (e.g. /customer/5/edit).
function isActive(match) {
    const patterns = Array.isArray(match) ? match : [match];
    const hasExactMenuMatch = allMenuMatches.value.some((p) => '/' + p === currentPath.value);
    return patterns.some((p) => {
        const target = '/' + p;
        if (currentPath.value === target) return true;
        if (hasExactMenuMatch) return false;
        return currentPath.value.startsWith(target + '/');
    });
}

function isDashboard() {
    return currentPath.value === '/' || currentPath.value === '/panel/dashboard';
}

const isPosPage = computed(() => page.component === 'Sale/Pos');

function groupHasActive(group) {
    return group.items.some((item) => isActive(item.match));
}

function isGroupOpen(group) {
    if (openGroups.value.has(group.key)) return true;
    if (menuSearch.value.trim() !== '') {
        return group.items.some((item) => item.label.toLowerCase().includes(menuSearch.value.trim().toLowerCase()));
    }
    return groupHasActive(group);
}

function closeOnMobileNav() {
    if (!isDesktop()) sidebarOpen.value = false;
}

function toggleGroup(key, event) {
    const wasOpen = openGroups.value.has(key);
    openGroups.value.clear();
    if (!wasOpen) {
        openGroups.value.add(key);
        const button = event?.currentTarget;
        if (button) {
            nextTick(() => {
                button.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        }
    }
}

const filteredGroups = computed(() => {
    const term = menuSearch.value.trim().toLowerCase();
    if (!term) return menuGroups.value;
    return menuGroups.value
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.label.toLowerCase().includes(term)),
        }))
        .filter((group) => group.items.length > 0);
});

const profileOpen = ref(false);

if (page.props.flash?.success) toast.success(page.props.flash.success);
if (page.props.flash?.error) toast.error(page.props.flash.error);
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <ToastStack />

        <!-- Impersonation banner -->
        <div v-if="impersonating" class="fixed inset-x-0 top-0 z-50 flex h-8 items-center justify-center gap-3 bg-amber-500 text-xs font-medium text-white print:hidden">
            <span>{{ t('impersonation.logged_in_as', { name: auth?.name }) }}</span>
            <a href="/switch-back" class="rounded bg-white/20 px-2 py-0.5 hover:bg-white/30">{{ t('impersonation.switch_back') }}</a>
        </div>

        <!-- Header -->
        <header v-if="!isPosPage" :class="[impersonating ? 'top-8' : 'top-0', 'fixed inset-x-0 z-40 grid h-14 grid-cols-[auto_1fr_auto] items-center gap-3 border-b-2 border-amber-400 bg-brand-600 px-4 shadow-sm print:hidden']">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/10 hover:text-white"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <i class="bi bi-list text-xl"></i>
                </button>
                <Link href="/" class="flex items-center gap-2">
                    <img v-if="company.logo" :src="'/' + company.logo" class="h-8 w-8 shrink-0 rounded-md object-cover" />
                    <span class="hidden truncate text-base font-semibold tracking-wide text-brand-100 md:inline">{{ company.title || t('topbar.application') }}</span>
                </Link>
            </div>

            <div class="flex justify-center">
                <GlobalSearch />
            </div>

            <div class="flex items-center gap-3">
                <LanguageSwitcher />
                <NotificationBell />
                <BranchSwitcher />
                <div class="relative">
                    <button
                        type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-lg py-1.5 pl-1.5 pr-2.5 transition hover:bg-white/10"
                        @click="profileOpen = !profileOpen"
                    >
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-sm font-semibold text-white shadow-sm ring-2 ring-white/30">
                            {{ (auth?.name || '?').charAt(0).toUpperCase() }}
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block text-sm font-semibold leading-tight text-white">{{ auth?.name }}</span>
                            <span class="block text-xs leading-tight text-brand-100">{{ auth?.designation || auth?.role }}</span>
                        </span>
                        <i class="bi bi-chevron-down text-xs text-brand-100 transition-transform" :class="profileOpen ? 'rotate-180' : ''"></i>
                    </button>
                    <div
                        v-if="profileOpen"
                        class="absolute right-0 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1.5 shadow-xl"
                        @click="profileOpen = false"
                    >
                        <div class="border-b border-slate-100 px-4 py-2.5">
                            <span class="block truncate text-sm font-semibold text-slate-800">{{ auth?.name }}</span>
                            <span class="block truncate text-xs text-slate-400">{{ auth?.email || auth?.designation }}</span>
                        </div>
                        <Link href="/user-profile" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
                            <i class="bi bi-person text-slate-400"></i> {{ t('nav.my_profile') }}
                        </Link>
                        <a href="/logout" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                            <i class="bi bi-box-arrow-right"></i> {{ t('nav.sign_out') }}
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Sidebar -->
        <aside
            v-if="!isPosPage"
            class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col overflow-hidden bg-brand-600 transition-transform print:hidden"
            :class="[impersonating ? 'top-[88px]' : 'top-14', sidebarOpen ? 'translate-x-0' : '-translate-x-full']"
        >
            <div class="shrink-0 p-3">
                <div class="relative">
                    <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                    <input
                        ref="menuSearchInput"
                        v-model="menuSearch"
                        type="search"
                        :placeholder="t('nav.search_menu')"
                        class="w-full rounded-full border-0 bg-white py-2 pl-9 pr-3 text-sm text-slate-700 placeholder:text-slate-400 shadow-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
                    />
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto pb-6 sidebar-scroll">
                <Link
                    href="/"
                    class="flex items-center gap-3 border-l-4 px-4 py-3 text-sm font-medium transition"
                    :class="isDashboard() ? 'border-amber-400 bg-brand-700 text-white' : 'border-transparent text-brand-100 hover:bg-brand-700/60 hover:text-white'"
                    @click="closeOnMobileNav"
                >
                    <i class="bi bi-grid-1x2-fill text-base"></i>
                    <span>{{ t('nav.dashboard') }}</span>
                </Link>

                <div v-for="(group, index) in filteredGroups" :key="group.key">
                    <div
                        v-if="group.section && group.section !== filteredGroups[index - 1]?.section"
                        class="flex items-center gap-2 px-4 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wider text-brand-200/80"
                    >
                        <span>{{ group.sectionLabel }}</span>
                        <span class="h-px flex-1 bg-white/15"></span>
                    </div>
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 border-l-4 px-4 py-3 text-left text-sm font-medium transition"
                        :class="groupHasActive(group) ? 'border-amber-400 bg-brand-700 text-white' : 'border-transparent text-brand-100 hover:bg-brand-700/60 hover:text-white'"
                        @click="toggleGroup(group.key, $event)"
                    >
                        <i class="bi text-base" :class="group.icon"></i>
                        <span class="flex-1">{{ group.label }}</span>
                        <i class="bi bi-chevron-down text-xs transition-transform" :class="isGroupOpen(group) ? 'rotate-180' : ''"></i>
                    </button>
                    <div v-show="isGroupOpen(group)" class="space-y-0.5 bg-brand-700/40 py-1.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.uri"
                            :href="item.uri || '#'"
                            class="flex items-center gap-2.5 py-2 pl-11 pr-4 text-sm transition"
                            :class="isActive(item.match) ? 'font-semibold text-amber-300' : 'text-brand-100 hover:text-white'"
                            @click="closeOnMobileNav"
                        >
                            <i class="bi bi-circle-fill text-[5px]" :class="isActive(item.match) ? 'text-amber-300' : 'text-white/40'"></i>
                            <span class="flex-1 truncate">{{ item.label }}</span>
                        </Link>
                    </div>
                </div>

                <!-- a normal menu entry that scrolls with the rest (not pinned to the bottom) -->
                <button
                    v-if="!menuSearch.trim() || 'license upgrade'.includes(menuSearch.trim().toLowerCase())"
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-3 border-l-4 border-transparent px-4 py-3 text-left text-sm font-medium text-brand-100 transition hover:bg-brand-700/60 hover:text-white"
                    @click="openLicensePanel"
                >
                    <i class="bi bi-arrow-repeat text-base"></i>
                    <span class="flex-1">License &amp; Upgrade</span>
                </button>

                <div v-if="menuSearch.trim() && filteredGroups.length === 0" class="px-4 py-6 text-center text-sm text-brand-200">
                    {{ t('nav.no_menu_match', { term: menuSearch }) }}
                </div>

            </nav>

            <div class="shrink-0 border-t border-white/10 bg-brand-800">
                <div class="flex items-center justify-between px-4 py-2.5">
                    <span class="flex items-center gap-1.5 text-xs font-medium text-brand-100">
                        {{ company.title }} v{{ page.props.appVersion }}
                    </span>
                    <button
                        type="button"
                        @click="onToggleTheme"
                        :title="darkMode ? t('topbar.light_mode') : t('topbar.dark_mode')"
                        class="flex h-6 w-6 cursor-pointer items-center justify-center rounded-full text-brand-100 transition hover:bg-brand-700 hover:text-white"
                    >
                        <i class="bi text-sm" :class="darkMode ? 'bi-moon-stars' : 'bi-sun'"></i>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Backdrop (mobile) -->
        <div v-if="sidebarOpen && !isPosPage" class="fixed inset-0 z-20 bg-slate-900/40 lg:hidden print:hidden" @click="sidebarOpen = false"></div>

        <!-- Content -->
        <main class="transition-all print:!pt-0 print:!pl-0" :class="isPosPage ? '' : [impersonating ? 'pt-[88px]' : 'pt-14', sidebarOpen ? 'lg:pl-64' : '']">
            <slot />
            <footer v-if="!isPosPage" class="px-4 py-3 text-center text-[11px] font-bold text-emerald-600 print:hidden">
                <a :href="footerCreditUrl" target="_blank" rel="noopener noreferrer" class="hover:text-emerald-700">
                    <span v-for="(ch, i) in footerCreditChars" :key="i">{{ ch }}</span>
                </a>
                <span v-if="page.props.appVersion" class="ml-1">| {{ company.title }} {{ page.props.appVersion }}</span>
            </footer>
        </main>
    </div>
</template>

<style scoped>
.sidebar-scroll::-webkit-scrollbar {
    width: 6px;
}
.sidebar-scroll::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.12);
    border-radius: 999px;
}
.sidebar-scroll::-webkit-scrollbar-track {
    background: transparent;
}
</style>
