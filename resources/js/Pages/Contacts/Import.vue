<script setup>
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    kind: { type: String, required: true },
    columns: { type: Array, required: true },
    result: { type: Object, default: null },
});

const plural = computed(() => `${props.kind}s`);
const form = useForm({ file: null });
const submit = () => form.post(route(`${plural.value}.import.store`), { forceFormData: true, onSuccess: () => form.reset() });

const notes = computed(() => ({
    code: 'Leave empty for new contacts; an existing code updates that contact',
    name: 'Required',
    phone: props.kind === 'customer' ? 'Required for customers; must be unique' : 'Must be unique',
    gstin: 'Validated; fills the state',
    state: 'GST state code (24) or name (Gujarat)',
    group: 'Name of an existing customer group',
    credit_limit: '0 = no credit sales',
    payment_terms_days: 'Suppliers: pay within N days',
    date_of_birth: 'YYYY-MM-DD',
    anniversary: 'YYYY-MM-DD',
    opening_balance: 'New contacts only. Positive = they owe us, negative = we owe them',
}));
</script>

<template>
    <AppLayout :title="`Import ${plural}`">
        <PageHeader :title="`Import ${plural}`" description="Add or update many contacts from a CSV file (Excel → Save as CSV).">
            <template #actions>
                <ButtonLink :href="route(`${plural}.index`)" variant="secondary">Back</ButtonLink>
                <a
                    :href="route(`${plural}.import.template`)"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                >
                    <ArrowDownTrayIcon class="h-4 w-4" /> Download template
                </a>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card title="Upload">
                <form class="space-y-4" @submit.prevent="submit">
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                        @change="form.file = $event.target.files[0]"
                    />
                    <InputError :message="form.errors.file" />
                    <PrimaryButton :disabled="!form.file || form.processing">{{ form.processing ? 'Importing…' : 'Import' }}</PrimaryButton>
                </form>

                <div v-if="result" class="mt-6 rounded-md border border-gray-200 p-4 text-sm">
                    <p class="font-medium text-gray-900">{{ result.created }} created · {{ result.updated }} updated · {{ result.errors.length }} skipped</p>
                    <ul v-if="result.errors.length" class="mt-3 max-h-64 space-y-1 overflow-y-auto text-xs text-red-700">
                        <li v-for="e in result.errors" :key="e.row"><strong>Row {{ e.row }}:</strong> {{ e.message }}</li>
                    </ul>
                </div>
            </Card>

            <Card title="Columns">
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="c in columns" :key="c" class="border-b border-gray-100 last:border-0">
                            <td class="py-1.5 pr-4 font-mono text-xs text-gray-800">{{ c }}</td>
                            <td class="py-1.5 text-xs text-gray-500">{{ notes[c] ?? 'Optional' }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>
    </AppLayout>
</template>
