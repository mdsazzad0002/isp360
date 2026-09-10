<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';

const form = reactive({ username: '', password: '' });
const errors = reactive({ username: '', password: '' });
const submitting = ref(false);
const generalError = ref('');
const showPassword = ref(false);
const shake = ref(false);
const usernameFocused = ref(false);
const passwordFocused = ref(false);

const FEATURES = [
    { icon: 'bi-box-seam', text: 'Real-time inventory tracking' },
    { icon: 'bi-graph-up-arrow', text: 'Sales & purchase insights' },
    { icon: 'bi-shield-check', text: 'Secure, licensed access' },
];

function triggerShake() {
    shake.value = false;
    requestAnimationFrame(() => {
        shake.value = true;
    });
}

async function login() {
    submitting.value = true;
    errors.username = '';
    errors.password = '';
    generalError.value = '';
    try {
        await axios.post('/login', form);
        window.location.href = '/panel/dashboard';
    } catch (err) {
        submitting.value = false;
        const r = err.response?.data;
        if (r?.errors && typeof r.errors === 'object') {
            Object.assign(errors, r.errors);
        }
        generalError.value = r?.message || 'Login failed';
        triggerShake();
    }
}
</script>

<template>
    <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-950 px-4 py-10">
        <!-- Ambient background -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-brand-600/30 blur-3xl animate-blob"></div>
            <div class="absolute -bottom-32 -right-24 h-96 w-96 rounded-full bg-amber-500/20 blur-3xl animate-blob animation-delay-2000"></div>
            <div class="absolute left-1/3 top-1/4 h-72 w-72 rounded-full bg-brand-400/10 blur-3xl animate-blob animation-delay-4000"></div>
            <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.04)_1px,transparent_1px)] bg-[size:36px_36px]"></div>
        </div>

        <div class="relative grid w-full max-w-5xl grid-cols-1 overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur-xl lg:grid-cols-2">
            <!-- Brand panel -->
            <div class="relative hidden flex-col justify-between bg-gradient-to-br from-brand-700 via-brand-600 to-brand-800 p-10 text-white lg:flex">
                <div class="pointer-events-none absolute inset-0 opacity-20">
                    <div class="absolute -left-10 -top-10 h-56 w-56 rounded-full border border-white/30"></div>
                    <div class="absolute bottom-10 right-0 h-40 w-40 rounded-full border border-white/20"></div>
                </div>

                <div class="relative">
                    <div class="mb-8 flex items-center gap-2.5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/15 text-xl backdrop-blur">
                            <i class="bi bi-boxes"></i>
                        </span>
                        <span class="text-xl font-bold tracking-tight">Business Management Software</span>
                    </div>
                    <h1 class="text-3xl font-bold leading-tight">
                        Manage your business,
                        <span class="text-amber-300">effortlessly.</span>
                    </h1>
                    <p class="mt-3 max-w-sm text-sm text-brand-100">
                        A complete inventory, sales, purchase and accounting workspace built for growing teams.
                    </p>

                    <ul class="mt-8 space-y-3">
                        <li v-for="f in FEATURES" :key="f.text" class="flex items-center gap-3 text-sm text-brand-50">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/10">
                                <i class="bi text-amber-300" :class="f.icon"></i>
                            </span>
                            {{ f.text }}
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Form panel -->
            <div class="flex flex-col justify-center p-8 sm:p-12">
                <div class="mb-8 lg:hidden">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600/20 text-xl text-brand-400">
                            <i class="bi bi-boxes"></i>
                        </span>
                        <span class="text-xl font-bold text-white">Business Management Software</span>
                    </div>
                </div>

                <h2 class="text-2xl font-bold text-white">Welcome back</h2>
                <p class="mb-8 mt-1 text-sm text-slate-400">Sign in to continue to your dashboard.</p>

                <form @submit.prevent="login" class="space-y-5" :class="{ 'animate-shake': shake }" @animationend="shake = false">
                    <div>
                        <label for="username" class="mb-1.5 block text-sm font-medium text-slate-300">Email / Username</label>
                        <div
                            class="flex items-center gap-2 rounded-xl border bg-slate-950/60 px-3.5 transition-all duration-200"
                            :class="[
                                errors.username ? 'border-red-500/60' : usernameFocused ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-slate-700 hover:border-slate-600',
                            ]"
                        >
                            <i class="bi bi-person text-slate-500" :class="usernameFocused ? 'text-brand-400' : ''"></i>
                            <input
                                id="username"
                                v-model="form.username"
                                type="text"
                                autocomplete="off"
                                autofocus
                                required
                                placeholder="name@example.com"
                                class="w-full border-none bg-transparent px-2 py-2.5 text-sm text-white placeholder:text-slate-600 outline-none"
                                @focus="usernameFocused = true"
                                @blur="usernameFocused = false"
                            />
                        </div>
                        <p v-if="errors.username" class="mt-1.5 flex items-center gap-1 text-xs text-red-400">
                            <i class="bi bi-exclamation-circle"></i> {{ errors.username }}
                        </p>
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-slate-300">Password</label>
                        <div
                            class="flex items-center gap-2 rounded-xl border bg-slate-950/60 px-3.5 transition-all duration-200"
                            :class="[
                                errors.password ? 'border-red-500/60' : passwordFocused ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-slate-700 hover:border-slate-600',
                            ]"
                        >
                            <i class="bi bi-lock text-slate-500" :class="passwordFocused ? 'text-brand-400' : ''"></i>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="off"
                                required
                                placeholder="••••••••"
                                class="w-full border-none bg-transparent px-2 py-2.5 text-sm text-white placeholder:text-slate-600 outline-none"
                                @focus="passwordFocused = true"
                                @blur="passwordFocused = false"
                            />
                            <i
                                class="bi cursor-pointer text-slate-500 transition hover:text-slate-300"
                                :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"
                                @click="showPassword = !showPassword"
                            ></i>
                        </div>
                        <p v-if="errors.password" class="mt-1.5 flex items-center gap-1 text-xs text-red-400">
                            <i class="bi bi-exclamation-circle"></i> {{ errors.password }}
                        </p>
                    </div>

                    <p v-if="generalError" class="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">
                        <i class="bi bi-exclamation-triangle"></i> {{ generalError }}
                    </p>

                    <button
                        type="submit"
                        :disabled="submitting"
                        class="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r cursor-pointer from-brand-500 to-brand-600 py-3 text-sm font-semibold text-white shadow-lg shadow-brand-600/20 transition-all duration-200 hover:shadow-xl hover:shadow-brand-600/30 hover:brightness-110 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <i v-if="submitting" class="bi bi-arrow-repeat animate-spin"></i>
                        <i v-else class="bi bi-box-arrow-in-right transition-transform group-hover:translate-x-0.5"></i>
                        {{ submitting ? 'Signing in...' : 'Sign In' }}
                    </button>
                </form>

                <p class="mt-8 text-center text-xs text-slate-500">
                    Design &amp; Develop By
                    <a target="_blank" href="https://bdsofttechnology.com/" class="text-brand-400 hover:underline">BD Soft Technology</a>
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
.animate-blob {
    animation: blob 10s infinite ease-in-out;
}
.animation-delay-2000 {
    animation-delay: 2s;
}
.animation-delay-4000 {
    animation-delay: 4s;
}

@keyframes shake {
    10%, 90% { transform: translateX(-1px); }
    20%, 80% { transform: translateX(2px); }
    30%, 50%, 70% { transform: translateX(-4px); }
    40%, 60% { transform: translateX(4px); }
}
.animate-shake {
    animation: shake 0.5s ease-in-out;
}
</style>
