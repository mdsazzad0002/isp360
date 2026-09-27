<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ToastStack from '../Components/ToastStack.vue';
import { useToast } from '../lib/toast';
import { isDarkMode, toggleTheme, initTheme, watchSystemTheme } from '../lib/theme';

// Same shell as the admin AppLayout (brand topbar + sidebar), with the menu of the
// portal (customer or reseller) the visitor is logged into.
const props = defineProps({
    userName: { type: String, default: '' },
    logoutUrl: { type: String, required: true },
});

const page = usePage();
const toast = useToast();
const company = computed(() => page.props.company ?? {});
const impersonating = computed(() => !!page.props.portalImpersonating);
const portalUser = computed(() => page.props.portalUser ?? null);
const isReseller = computed(() => portalUser.value?.type === 'reseller' || props.logoutUrl.startsWith('/reseller'));
const displayName = computed(() => portalUser.value?.name || props.userName || '');

const MENUS = {
    customer: [
        {
            section: 'My Account',
            items: [
                { uri: '/customer-portal/dashboard', icon: 'bi-grid-1x2-fill', label: 'Dashboard' },
                { uri: '/customer-portal/connections', icon: 'bi-ethernet', label: 'My Connections' },
                { uri: '/customer-portal/pay', icon: 'bi-wallet2', label: 'Pay Bill & Wallet' },
                { uri: '/customer-portal/tickets', icon: 'bi-life-preserver', label: 'Support' },
                { uri: '/customer-portal/profile', icon: 'bi-person-circle', label: 'My Profile' },
            ],
        },
    ],
    reseller: [
        {
            section: 'Business',
            items: [
                { uri: '/reseller/dashboard', icon: 'bi-grid-1x2-fill', label: 'Dashboard' },
                { uri: '/reseller/connections', icon: 'bi-ethernet', label: 'My Connections' },
                { uri: '/reseller/packages', icon: 'bi-speedometer2', label: 'My Packages' },
            ],
        },
        {
            section: 'Money',
            items: [
                { uri: '/reseller/payments', icon: 'bi-cash-coin', label: 'Payment' },
                { uri: '/reseller/ledger', icon: 'bi-journal-text', label: 'My Ledger' },
                { uri: '/reseller/withdrawals', icon: 'bi-wallet2', label: 'Wallet & Withdrawal' },
            ],
        },
        {
            section: 'Account',
            items: [
                { uri: '/reseller/tickets', icon: 'bi-life-preserver', label: 'Support Tickets' },
                { uri: '/reseller/profile', icon: 'bi-person-circle', label: 'My Profile' },
            ],
        },
    ],
};
const menu = computed(() => MENUS[isReseller.value ? 'reseller' : 'customer']);
const homeUri = computed(() => menu.value[0].items[0].uri);
const roleLabel = computed(() => (isReseller.value ? 'Reseller' : 'Customer'));
const profileUri = computed(() => (isReseller.value ? '/reseller/profile' : '/customer-portal/profile'));

const currentPath = computed(() => (page.url ?? '').split('?')[0].split('#')[0]);
function isActive(uri) {
    return currentPath.value === uri || currentPath.value.startsWith(uri + '/');
}

const DESKTOP_BREAKPOINT = 1024;
const isDesktop = () => typeof window !== 'undefined' && window.innerWidth >= DESKTOP_BREAKPOINT;
const sidebarOpen = ref(isDesktop());
const profileOpen = ref(false);
const darkMode = ref(isDarkMode());
let unwatchSystemTheme;

function handleResize() {
    sidebarOpen.value = isDesktop();
}
function closeOnMobileNav() {
    if (!isDesktop()) sidebarOpen.value = false;
}
function onToggleTheme() {
    darkMode.value = toggleTheme();
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

if (page.props.flash?.success) toast.success(page.props.flash.success);
if (page.props.flash?.error) toast.error(page.props.flash.error);
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <ToastStack />

        <!-- Admin "login as customer" banner -->
        <div v-if="impersonating" class="fixed inset-x-0 top-0 z-50 flex h-8 items-center justify-center gap-3 bg-amber-500 text-xs font-medium text-white print:hidden">
            <span><i class="bi bi-eye"></i> Viewing the {{ roleLabel.toLowerCase() }} portal as {{ displayName || 'this account' }}</span>
            <a :href="logoutUrl" class="rounded bg-white/20 px-2 py-0.5 hover:bg-white/30">Back to admin</a>
        </div>

        <!-- Header -->
        <header :class="[impersonating ? 'top-8' : 'top-0', 'fixed inset-x-0 z-40 flex h-14 items-center justify-between gap-3 border-b-2 border-amber-400 bg-brand-600 px-4 shadow-sm print:hidden']">
            <div class="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/10 hover:text-white"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <i class="bi bi-list text-xl"></i>
                </button>
                <Link :href="homeUri" class="flex min-w-0 items-center gap-2">
                    <img v-if="company.logo" :src="'/' + company.logo" class="h-8 w-8 shrink-0 rounded-md object-cover" />
                    <span class="hidden truncate text-base font-semibold tracking-wide text-brand-100 md:inline">{{ company.title }}</span>
                </Link>
                <span class="hidden rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium text-white sm:inline">{{ roleLabel }} Portal</span>
            </div>

            <div class="relative">
                <button type="button" class="flex cursor-pointer items-center gap-2 rounded-lg py-1.5 ps-1.5 pe-2.5 transition hover:bg-white/10" @click="profileOpen = !profileOpen">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-sm font-semibold text-white shadow-sm ring-2 ring-white/30">
                        {{ (displayName || '?').charAt(0).toUpperCase() }}
                    </span>
                    <span class="hidden text-start sm:block">
                        <span class="block text-sm font-semibold leading-tight text-white">{{ displayName }}</span>
                        <span class="block text-xs leading-tight text-brand-100">{{ roleLabel }}<span v-if="portalUser?.code"> · {{ portalUser.code }}</span></span>
                    </span>
                    <i class="bi bi-chevron-down text-xs text-brand-100 transition-transform" :class="profileOpen ? 'rotate-180' : ''"></i>
                </button>
                <div v-if="profileOpen" class="absolute end-0 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1.5 shadow-xl" @click="profileOpen = false">
                    <div class="border-b border-slate-100 px-4 py-2.5">
                        <span class="block truncate text-sm font-semibold text-slate-800">{{ displayName }}</span>
                        <span class="block truncate text-xs text-slate-400">{{ portalUser?.email || roleLabel }}</span>
                    </div>
                    <Link :href="profileUri" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
                        <i class="bi bi-person text-slate-400"></i> My Profile
                    </Link>
                    <a :href="logoutUrl" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                        <i class="bi bi-box-arrow-right"></i> {{ impersonating ? 'Back to admin' : 'Sign out' }}
                    </a>
                </div>
            </div>
        </header>
        <div v-if="profileOpen" class="fixed inset-0 z-30" @click="profileOpen = false"></div>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 start-0 z-30 flex w-64 flex-col overflow-hidden bg-brand-600 transition-transform print:hidden"
            :class="[impersonating ? 'top-[88px]' : 'top-14', sidebarOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full']"
        >
            <nav class="flex-1 overflow-y-auto pb-6 pt-2 sidebar-scroll">
                <div v-for="group in menu" :key="group.section">
                    <div class="flex items-center gap-2 px-4 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wider text-brand-200/80">
                        <span>{{ group.section }}</span>
                        <span class="h-px flex-1 bg-white/15"></span>
                    </div>
                    <Link
                        v-for="item in group.items"
                        :key="item.uri"
                        :href="item.uri"
                        class="flex items-center gap-3 border-s-4 px-4 py-3 text-sm font-medium transition"
                        :class="isActive(item.uri) ? 'border-amber-400 bg-brand-700 text-white' : 'border-transparent text-brand-100 hover:bg-brand-700/60 hover:text-white'"
                        @click="closeOnMobileNav"
                    >
                        <i class="bi text-base" :class="item.icon"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>
            </nav>

            <div class="shrink-0 border-t border-white/10 bg-brand-800">
                <a :href="logoutUrl" class="flex w-full items-center gap-3 border-b border-white/10 px-4 py-2.5 text-sm font-medium text-brand-100 transition hover:bg-brand-700/60 hover:text-white">
                    <i class="bi bi-box-arrow-right text-base"></i>
                    <span>{{ impersonating ? 'Back to admin' : 'Sign out' }}</span>
                </a>
                <div class="flex items-center justify-between px-4 py-2.5">
                    <span class="text-xs font-medium text-brand-100">{{ company.title }} v{{ page.props.appVersion }}</span>
                    <button
                        type="button"
                        :title="darkMode ? 'Light mode' : 'Dark mode'"
                        class="flex h-6 w-6 cursor-pointer items-center justify-center rounded-full text-brand-100 transition hover:bg-brand-700 hover:text-white"
                        @click="onToggleTheme"
                    >
                        <i class="bi text-sm" :class="darkMode ? 'bi-moon-stars' : 'bi-sun'"></i>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Backdrop (mobile) -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-20 bg-slate-900/40 lg:hidden print:hidden" @click="sidebarOpen = false"></div>

        <!-- Content -->
        <main class="transition-all print:!ps-0 print:!pt-0" :class="[impersonating ? 'pt-[88px]' : 'pt-14', sidebarOpen ? 'lg:ps-64' : '']">
            <div class="p-4 sm:p-6">
                <slot />
            </div>
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
