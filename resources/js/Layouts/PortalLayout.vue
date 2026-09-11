<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    userName: { type: String, default: '' },
    logoutUrl: { type: String, required: true },
});

const page = usePage();
const company = computed(() => page.props.company ?? {});
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 shadow-sm sm:px-6">
            <div class="flex items-center gap-2.5">
                <img v-if="company.logo" :src="'/' + company.logo" class="h-8 w-8 shrink-0 rounded-md object-cover" />
                <i v-else class="bi bi-boxes text-xl text-brand-600"></i>
                <span class="truncate text-base font-semibold text-slate-800">{{ company.title || 'Business Management Software' }}</span>
            </div>
            <div class="flex items-center gap-4">
                <span v-if="userName" class="hidden text-sm text-slate-600 sm:inline">{{ userName }}</span>
                <a :href="logoutUrl" class="inline-flex items-center gap-1.5 rounded-md border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </header>
        <main class="mx-auto max-w-5xl p-4 sm:p-6">
            <slot />
        </main>
    </div>
</template>
