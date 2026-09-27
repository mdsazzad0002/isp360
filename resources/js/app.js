import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18n } from './lib/i18n';
import { applyDefaultLocale } from './lib/locale';

createInertiaApp({
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // the company's language (country pack) until the user picks one
        applyDefaultLocale(i18n, props.initialPage.props.defaultLocale);
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
