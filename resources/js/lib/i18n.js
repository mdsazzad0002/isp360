import { createI18n } from 'vue-i18n';
import en from '../lang/en.json';
import bn from '../lang/bn.json';
import hi from '../lang/hi.json';
import ar from '../lang/ar.json';
import { resolveLocale } from './locale';

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: resolveLocale(),
    fallbackLocale: 'en',
    messages: { en, bn, hi, ar },
});
