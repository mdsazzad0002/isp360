// Active UI language: defaults to English, but an explicit user choice
// (stored in localStorage) always wins. Adding a new language later is just
// dropping another JSON file in resources/js/lang and adding its code here.
const STORAGE_KEY = 'locale';
const DEFAULT_LOCALE = 'en';

export const SUPPORTED_LOCALES = [
    { code: 'en', label: 'English' },
    { code: 'bn', label: 'বাংলা' },
    { code: 'hi', label: 'हिन्दी' },
    { code: 'ar', label: 'العربية', dir: 'rtl' },
];

export function localeDir(code) {
    return SUPPORTED_LOCALES.find((l) => l.code === code)?.dir ?? 'ltr';
}

export function getStoredLocale() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        return null;
    }
}

export function resolveLocale() {
    const stored = getStoredLocale();
    if (SUPPORTED_LOCALES.some((l) => l.code === stored)) return stored;
    return DEFAULT_LOCALE;
}

// The company's default language (set by its country pack), used only while the user hasn't
// picked one in the language switcher.
export function applyDefaultLocale(i18n, code) {
    if (getStoredLocale() || !SUPPORTED_LOCALES.some((l) => l.code === code)) return;
    const locale = i18n.global?.locale ?? i18n.locale;
    locale.value = code;
    document.documentElement.setAttribute('lang', code);
    document.documentElement.setAttribute('dir', localeDir(code));
}

// Accepts either the composer from useI18n() (has .locale directly) or the
// createI18n() instance itself (has .global.locale).
export function setLocale(i18n, code) {
    try {
        localStorage.setItem(STORAGE_KEY, code);
    } catch (e) {
        /* storage unavailable, ignore */
    }
    const locale = i18n.global?.locale ?? i18n.locale;
    locale.value = code;
    document.documentElement.setAttribute('lang', code);
    document.documentElement.setAttribute('dir', localeDir(code));
}
