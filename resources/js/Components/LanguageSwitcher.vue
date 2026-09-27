<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { SUPPORTED_LOCALES, setLocale } from '../lib/locale';

const i18n = useI18n();
const root = ref(null);
const open = ref(false);

function handleClickOutside(e) {
    if (root.value && !root.value.contains(e.target)) {
        open.value = false;
        document.removeEventListener('click', handleClickOutside);
    }
}

function toggleOpen() {
    open.value = !open.value;
    if (open.value) {
        document.addEventListener('click', handleClickOutside);
    } else {
        document.removeEventListener('click', handleClickOutside);
    }
}

function selectLocale(code) {
    setLocale(i18n, code);
    open.value = false;
}

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :title="$t('common.language')"
            class="flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-white/90 transition hover:bg-white/10 hover:text-white"
            @click="toggleOpen"
        >
            <i class="bi bi-translate text-base"></i>
            <span class="hidden sm:inline">{{ SUPPORTED_LOCALES.find((l) => l.code === $i18n.locale)?.label }}</span>
            <i class="bi bi-chevron-down text-xs transition-transform" :class="open ? 'rotate-180' : ''"></i>
        </button>

        <div
            v-show="open"
            class="absolute end-0 top-full z-[2000] mt-2 w-40 overflow-hidden rounded-md border border-slate-200 bg-white p-1 shadow-xl"
        >
            <button
                v-for="locale in SUPPORTED_LOCALES"
                :key="locale.code"
                type="button"
                class="flex w-full items-center gap-2.5 rounded px-3 py-2 text-start text-sm transition hover:bg-slate-50"
                :class="locale.code === $i18n.locale ? 'bg-brand-50 font-medium text-brand-600' : 'text-slate-700'"
                @click="selectLocale(locale.code)"
            >
                <i class="bi shrink-0" :class="locale.code === $i18n.locale ? 'bi-check-circle-fill text-brand-500' : 'bi-circle text-slate-300'"></i>
                <span>{{ locale.label }}</span>
            </button>
        </div>
    </div>
</template>
