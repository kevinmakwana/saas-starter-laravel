import { ref } from 'vue';

const STORAGE_KEY = 'theme';

const isDark = ref(typeof document !== 'undefined' && document.documentElement.classList.contains('dark'));

const systemQuery = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function stored(): string | null {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function apply(dark: boolean): void {
    document.documentElement.classList.toggle('dark', dark);
    isDark.value = dark;
}

// Until the user picks one, the theme follows the system and changes with it.
systemQuery?.addEventListener('change', (event) => {
    if (stored() === null) {
        apply(event.matches);
    }
});

/** The page theme. The first paint is handled by a script in the page head; this only changes it afterwards. */
export function useTheme() {
    const toggle = (): void => {
        const next = !isDark.value;
        apply(next);

        try {
            localStorage.setItem(STORAGE_KEY, next ? 'dark' : 'light');
        } catch {
            // Storage can be blocked (private windows); the choice then lasts for this page view only.
        }
    };

    return { isDark, toggle };
}
