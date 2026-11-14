import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** const { can } = usePermissions(); v-if="can('products.manage')" */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(() => new Set(page.props.auth?.user?.permissions ?? []));

    const can = (permission) => {
        if (!permission) return true;
        const list = Array.isArray(permission) ? permission : [permission];
        return list.some((p) => permissions.value.has(p));
    };

    return { can };
}
