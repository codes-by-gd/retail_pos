<script setup>
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';

defineProps({
    columns: { type: Array, required: true },
    result: { type: Object, default: null },
    currentBranch: { type: String, default: null },
});

const form = useForm({ file: null });
const submit = () => form.post(route('products.import.store'), { forceFormData: true, onSuccess: () => form.reset() });

const notes = {
    name: 'Required',
    article_no: 'Rows with the same article no. become one product with sizes/colours',
    selling_price: 'Required',
    sku: 'If it matches an existing item, only its prices are updated',
    barcode: 'Several barcodes separated by |; blank = auto EAN-13',
    category: 'Created if missing; use "Men > Shirts" for sub-categories',
    brand: 'Created if missing',
    material: 'Fabric, e.g. Cotton',
    unit: 'Short name of an existing unit (pcs, set, pair, mtr…); default pcs',
    gst: 'A GST rate name (e.g. Garments) or a flat % (5, 18); blank = Garments',
    opening_stock: 'Added to the current branch for new items',
};
</script>

<template>
    <AppLayout title="Import products">
        <PageHeader title="Import products" description="Add many single-type products at once from a CSV file (Excel → Save as CSV).">
            <template #actions>
                <a
                    :href="route('products.import.template')"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50"
                >
                    <ArrowDownTrayIcon class="h-4 w-4" /> Download template
                </a>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card title="Upload">
                <form class="space-y-4" @submit.prevent="submit">
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                        @change="form.file = $event.target.files[0]"
                    />
                    <InputError :message="form.errors.file" />
                    <p class="text-xs text-gray-500">Opening stock goes to <strong>{{ currentBranch }}</strong>. Max 5 MB.</p>
                    <PrimaryButton :disabled="!form.file || form.processing">{{ form.processing ? 'Importing…' : 'Import' }}</PrimaryButton>
                </form>

                <div v-if="result" class="mt-6 rounded-md border border-gray-200 p-4 text-sm">
                    <p class="font-medium text-gray-900">
                        {{ result.created }} created · {{ result.updated }} updated · {{ result.errors.length }} skipped
                    </p>
                    <ul v-if="result.errors.length" class="mt-3 max-h-64 space-y-1 overflow-y-auto text-xs text-red-700">
                        <li v-for="e in result.errors" :key="e.row"><strong>Row {{ e.row }}:</strong> {{ e.message }}</li>
                    </ul>
                </div>
            </Card>

            <Card title="Columns" description="Plus one column per size/colour attribute you use, named exactly like it: Colour, Size, Shirt Size, Waist, Kids Age.">
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
