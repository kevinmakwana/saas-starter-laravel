import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { i18n, setLanguage } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // The server decides the language; follow it on the first page and on every visit after.
        setLanguage(String(props.initialPage.props.locale ?? 'en'));
        // "success" rather than "navigate": changing the language comes back to the same URL, which Inertia treats as a replace.
        // Partial reloads (the notification bell) do not carry the locale, and leave the language alone.
        router.on('success', (event) => {
            const locale = event.detail.page.props.locale;
            if (typeof locale === 'string') {
                setLanguage(locale);
            }
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
