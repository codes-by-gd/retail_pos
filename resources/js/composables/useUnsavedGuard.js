import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Ask before leaving a form with unsaved changes (links, back button, closing the tab).
 *   useUnsavedGuard(() => form.isDirty && !form.processing);
 * Saving (POST/PUT…) is never blocked.
 */
export function useUnsavedGuard(isDirty, message = 'You have unsaved changes. Leave this page and lose them?') {
    const beforeUnload = (e) => {
        if (isDirty()) {
            e.preventDefault();
            e.returnValue = '';
        }
    };
    let off = () => {};
    onMounted(() => {
        window.addEventListener('beforeunload', beforeUnload);
        off = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' || visit.preserveState) return;
            if (isDirty() && !window.confirm(message)) return false;
        });
    });
    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', beforeUnload);
        off();
    });
}
