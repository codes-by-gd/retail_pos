import { ref } from 'vue';

/**
 * Light / dark / system theme. The choice is kept in localStorage (per device) and applied as the `dark`
 * class on <html>; an inline script in app.blade.php applies it before the app loads, so there is no flash.
 */
const KEY = 'pos-theme';
const read = () => {
    try {
        return localStorage.getItem(KEY) || 'system';
    } catch {
        return 'system';
    }
};
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

const choice = ref(read());
const isDark = ref(false);

const apply = () => {
    isDark.value = choice.value === 'dark' || (choice.value === 'system' && !!media?.matches);
    document.documentElement.classList.toggle('dark', isDark.value);
};
if (typeof window !== 'undefined') {
    apply();
    media?.addEventListener('change', apply);
}

export function useTheme() {
    const setTheme = (value) => {
        choice.value = value;
        try {
            localStorage.setItem(KEY, value);
        } catch {
            // private mode — still works for this page view
        }
        apply();
    };
    const cycle = () => setTheme({ light: 'dark', dark: 'system', system: 'light' }[choice.value] ?? 'light');

    return { theme: choice, isDark, setTheme, cycle };
}
