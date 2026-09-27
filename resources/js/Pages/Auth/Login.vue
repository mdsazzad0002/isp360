<script setup>
import { reactive, ref, computed, nextTick, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const company = computed(() => page.props.company ?? {});

// One tab per login portal. Class strings are written out in full so Tailwind picks them up.
const PORTALS = [
    {
        key: 'admin',
        label: 'Admin',
        icon: 'bi-shield-lock',
        heading: 'Staff & Admin',
        tagline: 'Run your whole network',
        accent: 'from office to fiber.',
        text: 'Customers, billing, MikroTik routers and accounts, all in one workspace.',
        userLabel: 'Email / Username',
        userPlaceholder: 'admin@example.com',
        features: [
            { icon: 'bi-people', text: 'Customer & connection management' },
            { icon: 'bi-receipt', text: 'Automated billing & collection' },
            { icon: 'bi-router', text: 'MikroTik network control' },
        ],
        panel: 'from-brand-700 via-brand-600 to-brand-900',
        pill: 'bg-brand-600 shadow-brand-600/40',
        button: 'from-brand-500 to-brand-600 shadow-brand-600/25 hover:shadow-brand-600/40',
        focus: 'border-brand-500 ring-2 ring-brand-500/20',
        iconFocus: 'text-brand-400',
        glow: 'bg-brand-600/30',
        link: 'text-brand-400',
    },
    {
        key: 'reseller',
        label: 'Reseller',
        icon: 'bi-diagram-3',
        heading: 'Reseller Portal',
        tagline: 'Grow your own',
        accent: 'subscriber base.',
        text: 'Create your own packages, follow your customers and keep an eye on dues.',
        userLabel: 'Username',
        userPlaceholder: 'reseller username',
        features: [
            { icon: 'bi-speedometer2', text: 'Create & price your own packages' },
            { icon: 'bi-person-lines-fill', text: 'Track every customer you bring' },
            { icon: 'bi-cash-coin', text: 'See dues at a glance' },
        ],
        panel: 'from-emerald-700 via-emerald-600 to-teal-900',
        pill: 'bg-emerald-600 shadow-emerald-600/40',
        button: 'from-emerald-500 to-teal-600 shadow-emerald-600/25 hover:shadow-emerald-600/40',
        focus: 'border-emerald-500 ring-2 ring-emerald-500/20',
        iconFocus: 'text-emerald-400',
        glow: 'bg-emerald-600/30',
        link: 'text-emerald-400',
    },
    {
        key: 'customer',
        label: 'Customer',
        icon: 'bi-wifi',
        heading: 'Customer Portal',
        tagline: 'Your internet account,',
        accent: 'anytime.',
        text: 'Check your bills, your package and your profile without calling the office.',
        userLabel: 'Username',
        userPlaceholder: 'your username',
        features: [
            { icon: 'bi-receipt-cutoff', text: 'View bills & payment history' },
            { icon: 'bi-speedometer', text: 'See your current package' },
            { icon: 'bi-person-gear', text: 'Update your profile & password' },
        ],
        panel: 'from-sky-700 via-sky-600 to-indigo-900',
        pill: 'bg-sky-600 shadow-sky-600/40',
        button: 'from-sky-500 to-indigo-600 shadow-sky-600/25 hover:shadow-sky-600/40',
        focus: 'border-sky-500 ring-2 ring-sky-500/20',
        iconFocus: 'text-sky-400',
        glow: 'bg-sky-600/30',
        link: 'text-sky-400',
    },
];

const STORAGE_KEY = 'login.portal';

function initialIndex() {
    let key = null;
    try {
        key = new URLSearchParams(window.location.search).get('portal') || localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        key = null;
    }
    const i = PORTALS.findIndex((p) => p.key === key);
    return i >= 0 ? i : 0;
}

// Each portal keeps its own form, errors and state, so switching tabs never mixes them up.
function blankState() {
    return {
        username: '', password: '', errors: { username: '', password: '', code: '' }, generalError: '', showPassword: false, submitting: false, success: false,
        // second step for accounts with two-factor login: a code from the app, or a recovery code
        twoFactor: false, code: '', useRecovery: false,
    };
}
const forms = reactive(Object.fromEntries(PORTALS.map((p) => [p.key, blankState()])));

const activeIndex = ref(initialIndex());
const direction = ref('next');
const portal = computed(() => PORTALS[activeIndex.value]);
const form = computed(() => forms[portal.value.key]);
const focused = ref('');
const shake = ref(false);
const usernameInput = ref(null);

function select(index) {
    if (index === activeIndex.value) return;
    direction.value = index > activeIndex.value ? 'next' : 'prev';
    activeIndex.value = index;
    try {
        localStorage.setItem(STORAGE_KEY, PORTALS[index].key);
    } catch (e) {
        // storage unavailable (private mode); the tab just won't be remembered
    }
}

function onTabKey(event) {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!step) return;
    event.preventDefault();
    const next = (activeIndex.value + step + PORTALS.length) % PORTALS.length;
    select(next);
    nextTick(() => document.getElementById(`tab-${PORTALS[next].key}`)?.focus());
}

function focusUsername() {
    usernameInput.value?.focus();
}

function triggerShake() {
    shake.value = false;
    requestAnimationFrame(() => (shake.value = true));
}

async function login() {
    const key = portal.value.key;
    const f = forms[key];
    f.submitting = true;
    f.errors = { username: '', password: '', code: '' };
    f.generalError = '';
    try {
        const res = f.twoFactor
            ? await axios.post('/login/two-factor', f.useRecovery ? { recovery_code: f.code } : { code: f.code })
            : await axios.post('/login', { username: f.username, password: f.password, portal: key });
        if (res.data.two_factor) {
            f.twoFactor = true;
            f.submitting = false;
            f.code = '';
            nextTick(() => document.getElementById(`${key}-code`)?.focus());
            return;
        }
        f.success = true;
        setTimeout(() => (window.location.href = res.data.redirect || '/panel/dashboard'), 650);
    } catch (err) {
        f.submitting = false;
        const r = err.response?.data;
        if (r?.errors && typeof r.errors === 'object') {
            Object.keys(f.errors).forEach((k) => (f.errors[k] = [].concat(r.errors[k] ?? '')[0]));
        }
        f.generalError = ['Unauthorized', 'Validation Error', 'Too many attempts'].includes(r?.message) ? '' : r?.message || 'Login failed';
        // the code step timed out: start again from the password
        if (f.twoFactor && err.response?.status === 401) backToPassword(f);
        triggerShake();
    }
}

function backToPassword(f) {
    f.twoFactor = false;
    f.code = '';
    f.useRecovery = false;
    f.password = '';
}

const mounted = ref(false);
onMounted(() => {
    requestAnimationFrame(() => (mounted.value = true));
    focusUsername();
});
</script>

<template>
    <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-950 px-4 py-10">
        <!-- Ambient background: blobs tint to the selected portal -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full blur-3xl transition-colors duration-700 animate-blob" :class="portal.glow"></div>
            <div class="absolute -bottom-32 -right-24 h-96 w-96 rounded-full bg-amber-500/15 blur-3xl animate-blob animation-delay-2000"></div>
            <div class="absolute left-1/3 top-1/4 h-72 w-72 rounded-full blur-3xl opacity-40 transition-colors duration-700 animate-blob animation-delay-4000" :class="portal.glow"></div>
            <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.04)_1px,transparent_1px)] bg-[size:36px_36px]"></div>
            <span v-for="n in 14" :key="n" class="particle" :style="{ left: `${(n * 53) % 100}%`, animationDelay: `${(n * 0.9) % 8}s`, animationDuration: `${9 + (n % 5) * 2}s` }"></span>
        </div>

        <div
            class="relative grid w-full max-w-5xl grid-cols-1 overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur-xl transition-all duration-700 lg:grid-cols-2"
            :class="mounted ? 'translate-y-0 opacity-100' : 'translate-y-6 opacity-0'"
        >
            <!-- Brand panel: one gradient layer per portal, cross-faded -->
            <div class="relative hidden min-h-[560px] overflow-hidden p-10 text-white lg:flex lg:flex-col lg:justify-between">
                <div
                    v-for="(p, i) in PORTALS"
                    :key="p.key"
                    class="absolute inset-0 bg-gradient-to-br transition-opacity duration-700"
                    :class="[p.panel, i === activeIndex ? 'opacity-100' : 'opacity-0']"
                ></div>

                <!-- Orbit rings -->
                <div class="pointer-events-none absolute -right-24 top-1/2 h-[420px] w-[420px] -translate-y-1/2">
                    <div class="absolute inset-0 rounded-full border border-white/15 animate-spin-slow">
                        <span class="absolute -top-1.5 left-1/2 h-3 w-3 -translate-x-1/2 rounded-full bg-white/70 shadow-[0_0_12px_rgba(255,255,255,0.8)]"></span>
                    </div>
                    <div class="absolute inset-12 rounded-full border border-white/10 animate-spin-reverse">
                        <span class="absolute -bottom-1 left-1/2 h-2 w-2 -translate-x-1/2 rounded-full bg-amber-300"></span>
                    </div>
                    <div class="absolute inset-24 rounded-full border border-dashed border-white/10"></div>
                </div>

                <div class="relative">
                    <div class="mb-10 flex items-center gap-2.5">
                        <img v-if="company.logo" :src="'/' + company.logo" class="h-10 w-10 rounded-xl object-cover" />
                        <span v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/15 text-xl backdrop-blur"><i class="bi bi-router"></i></span>
                        <span class="text-xl font-bold tracking-tight">{{ company.title || 'ISP360' }}</span>
                    </div>

                    <Transition name="panel" mode="out-in">
                        <div :key="portal.key">
                            <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium backdrop-blur">
                                <i class="bi" :class="portal.icon"></i> {{ portal.heading }}
                            </span>
                            <h1 class="mt-4 text-3xl font-bold leading-tight">
                                {{ portal.tagline }}
                                <span class="text-amber-300">{{ portal.accent }}</span>
                            </h1>
                            <p class="mt-3 max-w-sm text-sm text-white/75">{{ portal.text }}</p>

                            <ul class="mt-8 space-y-3">
                                <li v-for="(f, i) in portal.features" :key="f.text" class="feature flex items-center gap-3 text-sm text-white/90" :style="{ animationDelay: `${150 + i * 110}ms` }">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/10">
                                        <i class="bi text-amber-300" :class="f.icon"></i>
                                    </span>
                                    {{ f.text }}
                                </li>
                            </ul>
                        </div>
                    </Transition>
                </div>

                <!-- Floating portal badge -->
                <div class="relative flex items-end justify-between">
                    <Transition name="badge" mode="out-in">
                        <div :key="portal.key" class="animate-float flex h-20 w-20 items-center justify-center rounded-3xl bg-white/15 text-4xl shadow-2xl ring-1 ring-white/25 backdrop-blur">
                            <i class="bi" :class="portal.icon"></i>
                        </div>
                    </Transition>
                    <div class="flex gap-1.5">
                        <span v-for="(p, i) in PORTALS" :key="p.key" class="h-1.5 rounded-full bg-white transition-all duration-500" :class="i === activeIndex ? 'w-6 opacity-100' : 'w-1.5 opacity-40'"></span>
                    </div>
                </div>
            </div>

            <!-- Form panel -->
            <div class="flex flex-col justify-center p-6 sm:p-12">
                <div class="mb-6 flex items-center gap-2.5 lg:hidden">
                    <img v-if="company.logo" :src="'/' + company.logo" class="h-10 w-10 rounded-xl object-cover" />
                    <span v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-xl text-white"><i class="bi bi-router"></i></span>
                    <span class="text-xl font-bold text-white">{{ company.title || 'ISP360' }}</span>
                </div>

                <!-- Portal switcher -->
                <div role="tablist" aria-label="Login as" class="relative mb-8 grid grid-cols-3 rounded-2xl border border-white/10 bg-slate-950/60 p-1" @keydown="onTabKey">
                    <span
                        class="absolute bottom-1 left-1 top-1 rounded-xl shadow-lg transition-all duration-500 ease-[cubic-bezier(.65,0,.35,1)]"
                        :class="portal.pill"
                        :style="{ width: 'calc((100% - 0.5rem) / 3)', transform: `translateX(${activeIndex * 100}%)` }"
                    ></span>
                    <button
                        v-for="(p, i) in PORTALS"
                        :id="`tab-${p.key}`"
                        :key="p.key"
                        type="button"
                        role="tab"
                        :aria-selected="i === activeIndex"
                        :tabindex="i === activeIndex ? 0 : -1"
                        class="relative z-10 flex cursor-pointer items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-medium transition-colors duration-300"
                        :class="i === activeIndex ? 'text-white' : 'text-slate-400 hover:text-slate-200'"
                        @click="select(i)"
                    >
                        <i class="bi transition-transform duration-500" :class="[p.icon, i === activeIndex ? 'scale-110' : '']"></i>
                        {{ p.label }}
                    </button>
                </div>

                <div class="relative overflow-hidden">
                    <Transition :name="`slide-${direction}`" mode="out-in" @after-enter="focusUsername">
                        <div :key="portal.key" role="tabpanel" :aria-labelledby="`tab-${portal.key}`">
                            <h2 class="text-2xl font-bold text-white">{{ portal.label }} login</h2>
                            <p class="mb-7 mt-1 text-sm text-slate-400">Sign in to your {{ portal.heading.toLowerCase() }}.</p>

                            <form @submit.prevent="login" class="space-y-5" :class="{ 'animate-shake': shake }" @animationend="shake = false">
                                <div v-if="form.twoFactor">
                                    <label :for="`${portal.key}-code`" class="mb-1.5 block text-sm font-medium text-slate-300">
                                        {{ form.useRecovery ? 'Recovery code' : 'Code from your authenticator app' }}
                                    </label>
                                    <div class="flex items-center gap-2 rounded-xl border bg-slate-950/60 px-3.5" :class="form.errors.code ? 'border-red-500/60' : portal.focus">
                                        <i class="bi bi-shield-lock text-slate-500"></i>
                                        <input
                                            :id="`${portal.key}-code`"
                                            v-model="form.code"
                                            type="text"
                                            :inputmode="form.useRecovery ? 'text' : 'numeric'"
                                            autocomplete="one-time-code"
                                            required
                                            :maxlength="form.useRecovery ? 11 : 6"
                                            :placeholder="form.useRecovery ? 'xxxxx-xxxxx' : '123456'"
                                            class="w-full border-none bg-transparent px-2 py-2.5 text-sm tracking-widest text-white outline-none placeholder:text-slate-600"
                                        />
                                    </div>
                                    <p v-if="form.errors.code" class="mt-1.5 flex items-center gap-1 text-xs text-red-400">
                                        <i class="bi bi-exclamation-circle"></i> {{ form.errors.code }}
                                    </p>
                                    <div class="mt-2 flex justify-between text-xs">
                                        <button type="button" class="cursor-pointer text-slate-400 hover:text-slate-200" @click="form.useRecovery = !form.useRecovery; form.code = ''">
                                            {{ form.useRecovery ? 'Use the app code instead' : 'Lost your phone? Use a recovery code' }}
                                        </button>
                                        <button type="button" class="cursor-pointer text-slate-400 hover:text-slate-200" @click="backToPassword(form)">Back</button>
                                    </div>
                                </div>

                                <template v-else>
                                <div>
                                    <label :for="`${portal.key}-username`" class="mb-1.5 block text-sm font-medium text-slate-300">{{ portal.userLabel }}</label>
                                    <div
                                        class="flex items-center gap-2 rounded-xl border bg-slate-950/60 px-3.5 transition-all duration-200"
                                        :class="form.errors.username ? 'border-red-500/60' : focused === 'username' ? portal.focus : 'border-slate-700 hover:border-slate-600'"
                                    >
                                        <i class="bi bi-person transition-colors" :class="focused === 'username' ? portal.iconFocus : 'text-slate-500'"></i>
                                        <input
                                            :id="`${portal.key}-username`"
                                            ref="usernameInput"
                                            v-model="form.username"
                                            type="text"
                                            autocomplete="username"
                                            required
                                            :placeholder="portal.userPlaceholder"
                                            class="w-full border-none bg-transparent px-2 py-2.5 text-sm text-white outline-none placeholder:text-slate-600"
                                            @focus="focused = 'username'"
                                            @blur="focused = ''"
                                        />
                                    </div>
                                    <p v-if="form.errors.username" class="mt-1.5 flex items-center gap-1 text-xs text-red-400">
                                        <i class="bi bi-exclamation-circle"></i> {{ form.errors.username }}
                                    </p>
                                </div>

                                <div>
                                    <label :for="`${portal.key}-password`" class="mb-1.5 block text-sm font-medium text-slate-300">Password</label>
                                    <div
                                        class="flex items-center gap-2 rounded-xl border bg-slate-950/60 px-3.5 transition-all duration-200"
                                        :class="form.errors.password ? 'border-red-500/60' : focused === 'password' ? portal.focus : 'border-slate-700 hover:border-slate-600'"
                                    >
                                        <i class="bi bi-lock transition-colors" :class="focused === 'password' ? portal.iconFocus : 'text-slate-500'"></i>
                                        <input
                                            :id="`${portal.key}-password`"
                                            v-model="form.password"
                                            :type="form.showPassword ? 'text' : 'password'"
                                            autocomplete="current-password"
                                            required
                                            placeholder="••••••••"
                                            class="w-full border-none bg-transparent px-2 py-2.5 text-sm text-white outline-none placeholder:text-slate-600"
                                            @focus="focused = 'password'"
                                            @blur="focused = ''"
                                        />
                                        <button
                                            type="button"
                                            class="cursor-pointer text-slate-500 transition hover:text-slate-300"
                                            :aria-label="form.showPassword ? 'Hide password' : 'Show password'"
                                            @click="form.showPassword = !form.showPassword"
                                        >
                                            <i class="bi" :class="form.showPassword ? 'bi-eye-slash' : 'bi-eye'"></i>
                                        </button>
                                    </div>
                                    <p v-if="form.errors.password" class="mt-1.5 flex items-center gap-1 text-xs text-red-400">
                                        <i class="bi bi-exclamation-circle"></i> {{ form.errors.password }}
                                    </p>
                                </div>
                                </template>

                                <p v-if="form.generalError" class="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">
                                    <i class="bi bi-exclamation-triangle"></i> {{ form.generalError }}
                                </p>

                                <button
                                    type="submit"
                                    :disabled="form.submitting"
                                    class="group relative flex w-full cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r py-3 text-sm font-semibold text-white shadow-lg transition-all duration-300 hover:shadow-xl hover:brightness-110 active:scale-[0.98] disabled:cursor-not-allowed"
                                    :class="form.success ? 'from-emerald-500 to-emerald-600 shadow-emerald-600/30' : portal.button"
                                >
                                    <span class="shine"></span>
                                    <template v-if="form.success"><i class="bi bi-check2-circle animate-pop text-base"></i> Welcome! Redirecting…</template>
                                    <template v-else-if="form.submitting"><i class="bi bi-arrow-repeat animate-spin"></i> Signing in…</template>
                                    <template v-else-if="form.twoFactor"><i class="bi bi-shield-check"></i> Verify and sign in</template>
                                    <template v-else><i class="bi bi-box-arrow-in-right transition-transform group-hover:translate-x-0.5"></i> Sign in as {{ portal.label }}</template>
                                </button>
                            </form>

                            <p v-if="portal.key !== 'admin'" class="mt-5 text-center text-xs text-slate-500">
                                Don't have a login yet? Ask your {{ portal.key === 'customer' ? 'internet provider or reseller' : 'provider' }} to set one up.
                            </p>
                        </div>
                    </Transition>
                </div>

                <p class="mt-8 text-center text-xs text-slate-500">
                    Design &amp; Develop By
                    <a target="_blank" href="https://bdsofttechnology.com/" class="hover:underline" :class="portal.link">BD Soft Technology</a>
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes blob {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(20px, -30px) scale(1.1); }
    66% { transform: translate(-15px, 15px) scale(0.95); }
}
.animate-blob { animation: blob 10s infinite ease-in-out; }
.animation-delay-2000 { animation-delay: 2s; }
.animation-delay-4000 { animation-delay: 4s; }

@keyframes rise {
    0% { transform: translateY(0) scale(0.6); opacity: 0; }
    15% { opacity: 0.7; }
    100% { transform: translateY(-110vh) scale(1); opacity: 0; }
}
.particle {
    position: absolute;
    bottom: -10px;
    width: 4px;
    height: 4px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.5);
    box-shadow: 0 0 8px rgba(255, 255, 255, 0.6);
    animation: rise linear infinite;
}

.animate-spin-slow { animation: spin 28s linear infinite; }
.animate-spin-reverse { animation: spin 20s linear infinite reverse; }
@keyframes spin { to { transform: rotate(360deg); } }

@keyframes float {
    0%, 100% { transform: translateY(0) rotate(-3deg); }
    50% { transform: translateY(-10px) rotate(3deg); }
}
.animate-float { animation: float 5s ease-in-out infinite; }

@keyframes feature-in {
    from { opacity: 0; transform: translateX(-12px); }
    to { opacity: 1; transform: translateX(0); }
}
.feature { opacity: 0; animation: feature-in 0.5s ease-out forwards; }

/* Brand panel text swap */
.panel-enter-active, .panel-leave-active { transition: all 0.35s ease; }
.panel-enter-from { opacity: 0; transform: translateY(14px); }
.panel-leave-to { opacity: 0; transform: translateY(-14px); }

/* Floating badge swap */
.badge-enter-active, .badge-leave-active { transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
.badge-enter-from { opacity: 0; transform: scale(0.4) rotate(-40deg); }
.badge-leave-to { opacity: 0; transform: scale(0.4) rotate(40deg); }

/* Form slides in the direction of the chosen tab */
.slide-next-enter-active, .slide-next-leave-active,
.slide-prev-enter-active, .slide-prev-leave-active { transition: all 0.3s ease; }
.slide-next-enter-from, .slide-prev-leave-to { opacity: 0; transform: translateX(40px); }
.slide-next-leave-to, .slide-prev-enter-from { opacity: 0; transform: translateX(-40px); }

/* Light sweep across the submit button */
.shine {
    position: absolute;
    inset: 0;
    background: linear-gradient(110deg, transparent 30%, rgba(255, 255, 255, 0.25) 50%, transparent 70%);
    transform: translateX(-100%);
    animation: shine 3.5s ease-in-out infinite;
}
@keyframes shine {
    0%, 60% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

@keyframes pop {
    0% { transform: scale(0); }
    70% { transform: scale(1.3); }
    100% { transform: scale(1); }
}
.animate-pop { animation: pop 0.4s ease-out; }

@keyframes shake {
    10%, 90% { transform: translateX(-1px); }
    20%, 80% { transform: translateX(2px); }
    30%, 50%, 70% { transform: translateX(-4px); }
    40%, 60% { transform: translateX(4px); }
}
.animate-shake { animation: shake 0.5s ease-in-out; }

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
    .particle { display: none; }
    .feature { opacity: 1; }
}
</style>
