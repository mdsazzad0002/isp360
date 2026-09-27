<script setup>
import { ref } from 'vue';
import TwoFactorCard from '../../Components/TwoFactorCard.vue';

// Shown instead of the panel while the company requires two-factor login and it isn't set up yet.
defineProps({ base: String, home: String, logout: String });
const done = ref(false);
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10">
        <div class="w-full max-w-2xl space-y-4">
            <div class="text-center">
                <h1 class="text-lg font-semibold text-slate-800">Set up two-factor login</h1>
                <p class="mt-1 text-sm text-slate-500">Your company requires a code from an authenticator app at every login. Set it up once to continue.</p>
            </div>
            <TwoFactorCard :base="base" @enabled="done = true" />
            <div class="flex items-center justify-between">
                <a :href="logout" class="text-sm text-slate-500 hover:text-slate-700">Log out</a>
                <a v-if="done" :href="home" class="rounded-md bg-brand-500 px-5 py-2 text-sm font-medium text-white hover:bg-brand-600">I saved my recovery codes, continue</a>
            </div>
        </div>
    </div>
</template>
