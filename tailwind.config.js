import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

/**
 * Dark mode without touching every page: the palettes the app uses are CSS variables.
 *  - `--c-*`  = backgrounds, borders, rings, dividers (bg-white is the card surface `--surface`)
 *  - `--t-*`  = text colours (so text-gray-900 turns light while bg-indigo-600 + text-white stays as it is)
 * `.dark` on <html> swaps the variables (see the plugin below). Print views don't use Tailwind.
 */
const PALETTES = ['gray', 'indigo', 'red', 'amber', 'green', 'pink'];
const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`;
const scale = (kind, p) => Object.fromEntries(SHADES.map((s) => [s, v(`${kind}-${p}-${s}`)]));
const rgb = (hex) => {
    const h = hex.replace('#', '');
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(' ');
};

// dark mode: which light shade each shade becomes
const BG_DARK_GRAY = { 50: '#030712', 100: '#1f2937', 200: '#2b3544', 300: '#4b5563', 400: '#6b7280', 500: '#9ca3af', 600: '#d1d5db', 700: '#e5e7eb', 800: '#374151', 900: '#030712', 950: '#030712' };
const BG_DARK_ACCENT = { 50: 950, 100: 900, 200: 800, 300: 700, 400: 500, 500: 500, 600: 600, 700: 700, 800: 300, 900: 200, 950: 100 };
const TEXT_DARK = { 50: 950, 100: 900, 200: 700, 300: 600, 400: 500, 500: 400, 600: 400, 700: 300, 800: 200, 900: 100, 950: 50 };

const vars = (dark) => {
    const out = { '--surface': dark ? rgb('#111827') : '255 255 255' };
    for (const p of PALETTES) {
        for (const s of SHADES) {
            const bg = !dark ? colors[p][s] : p === 'gray' ? BG_DARK_GRAY[s] : colors[p][BG_DARK_ACCENT[s]];
            const tx = !dark ? colors[p][s] : colors[p][TEXT_DARK[s]];
            out[`--c-${p}-${s}`] = rgb(bg);
            out[`--t-${p}-${s}`] = rgb(tx);
        }
    }
    return out;
};

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: Object.fromEntries(PALETTES.map((p) => [p, scale('c', p)])),
            backgroundColor: { white: v('surface') },
            textColor: Object.fromEntries(PALETTES.map((p) => [p, scale('t', p)])),
        },
    },

    plugins: [
        forms,
        plugin(({ addBase }) => {
            addBase({
                ':root': { ...vars(false), colorScheme: 'light' },
                '.dark': { ...vars(true), colorScheme: 'dark' },
            });
        }),
    ],
};
