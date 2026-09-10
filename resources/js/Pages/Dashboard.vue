<script setup>
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const { t } = useI18n();
</script>

<template>
    <div class="mx-auto p-4 sm:p-6">
        <section class="rounded-xl bg-gradient-to-r from-brand-700 to-brand-600 px-6 py-5 text-white shadow-sm">
            <h1 class="text-xl font-bold">{{ t('dashboard.welcome_back', { name: page.props.auth?.user?.name }) }}</h1>
            <p class="mt-1 text-sm text-white/80">{{ t('dashboard.subtitle') }}</p>
        </section>
    </div>
</template>
