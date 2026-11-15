import { router } from '@inertiajs/vue3';
import { onMounted, reactive, watch } from 'vue';

/**
 * List filters kept in the URL and remembered for the browser session:
 * coming back to a list (from the menu, without a query string) restores the filters you last used.
 *   const filters = useListFilters('sales.index', { search: props.filters.search ?? '', … });
 */
export function useListFilters(routeName, initial, { params = {}, remember = true } = {}) {
    const filters = reactive({ ...initial });
    const key = `filters:${routeName}:${JSON.stringify(params)}`;
    const clean = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));

    watch(filters, () => {
        const query = clean();
        try {
            sessionStorage.setItem(key, JSON.stringify(query));
        } catch {
            /* private mode */
        }
        router.get(route(routeName, params), query, { preserveState: true, preserveScroll: true, replace: true });
    });

    onMounted(() => {
        if (!remember || window.location.search) return;
        try {
            const saved = JSON.parse(sessionStorage.getItem(key) || '{}');
            if (Object.keys(saved).length) router.get(route(routeName, params), saved, { replace: true, preserveScroll: true });
        } catch {
            /* ignore */
        }
    });

    return filters;
}
