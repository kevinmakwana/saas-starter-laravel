import { createI18n } from 'vue-i18n';
import de from './locales/de.json';
import en from './locales/en.json';
import es from './locales/es.json';

// All languages are bundled: together they are a few kilobytes, so there is no loading step and no flash of English.
export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en, es, de },
});

/** Switches the language of the screens and of the page itself (for screen readers and hyphenation). */
export function setLanguage(locale: string): void {
    if (i18n.global.availableLocales.includes(locale as 'en')) {
        i18n.global.locale.value = locale as 'en';
        document.documentElement.lang = locale;
    }
}
