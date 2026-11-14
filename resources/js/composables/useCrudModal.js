import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * State for "list + create/edit modal + delete confirm" screens.
 *   const crud = useCrudModal(() => ({ name: '', is_active: true }), {
 *       store: 'brands.store', update: 'brands.update', destroy: 'brands.destroy' });
 */
export function useCrudModal(blank, routes) {
    const form = useForm(blank());
    const editing = ref(null);
    const show = ref(false);
    const deleting = ref(null);

    const open = (values) => {
        form.defaults(values);
        form.reset();
        form.clearErrors();
        show.value = true;
    };

    const openCreate = () => {
        editing.value = null;
        open(blank());
    };

    const openEdit = (row) => {
        editing.value = row;
        open(Object.fromEntries(Object.keys(blank()).map((k) => [k, row[k] ?? blank()[k]])));
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => (show.value = false) };
        editing.value
            ? form.put(route(routes.update, editing.value.id), options)
            : form.post(route(routes.store), options);
    };

    const confirmDestroy = () =>
        router.delete(route(routes.destroy, deleting.value.id), {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        });

    return { form, editing, show, deleting, openCreate, openEdit, submit, confirmDestroy };
}
