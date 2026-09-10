<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
</script>

<template>
    <div class="flex min-h-[70vh] items-center justify-center p-4">
        <div class="w-full max-w-md text-center">
            <div class="mx-auto flex h-24 w-24 items-center justify-center rounded-full bg-brand-50 text-brand-500">
                <i class="bi bi-signpost-split text-4xl"></i>
            </div>
            <h1 class="mt-6 text-6xl font-bold text-slate-800">404</h1>
            <p class="mt-2 text-lg font-semibold text-slate-700">Page Not Found</p>
            <p class="mt-1 text-sm text-slate-500">The page you're looking for doesn't exist or may have been moved.</p>
            <div class="mt-6 flex items-center justify-center gap-3">
                <a href="/" class="flex items-center gap-1.5 rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-700">
                    <i class="bi bi-house-door"></i> Go to Dashboard
                </a>
                <button type="button" @click="history.back()" class="flex items-center gap-1.5 rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    <i class="bi bi-arrow-left"></i> Go Back
                </button>
            </div>
        </div>
    </div>
</template>
