<script setup>
import { useListFilters } from '@/composables/useListFilters';
import Badge from '@/Components/Badge.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, qty } from '@/utils/format';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, PhotoIcon, PlusIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, required: true },
    brands: { type: Array, required: true },
});

const { can } = usePermissions();

const filters = useListFilters('products.index', {
    search: props.filters.search ?? '',
    category_id: props.filters.category_id ? Number(props.filters.category_id) : null,
    brand_id: props.filters.brand_id ? Number(props.filters.brand_id) : null,
    status: props.filters.status ?? null,
});


// Selection for label printing (variant ids of the selected products)
const selected = ref(new Set());
const toggle = (id) => {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
};
const allOnPage = computed(() => props.products.data.length > 0 && props.products.data.every((p) => selected.value.has(p.id)));
const toggleAll = () => {
    const s = new Set(selected.value);
    props.products.data.forEach((p) => (allOnPage.value ? s.delete(p.id) : s.add(p.id)));
    selected.value = s;
};
const printLabels = () => {
    const variants = props.products.data.filter((p) => selected.value.has(p.id)).flatMap((p) => p.variant_ids);
    router.get(route('labels.index'), { variants });
};

const priceRange = (p) => (p.price_min === p.price_max ? money(p.price_min) : `${money(p.price_min)} – ${money(p.price_max)}`);

const columns = computed(() => [
    ...(can('labels.print') ? [{ key: 'select', label: '', class: 'w-10' }] : []),
    { key: 'name', label: 'Product' },
    { key: 'category', label: 'Category' },
    { key: 'gst', label: 'GST', align: 'right' },
    { key: 'price', label: 'Price', align: 'right' },
    { key: 'stock', label: 'Stock here', align: 'right' },
    { key: 'is_active', label: 'Status' },
]);
</script>

<template>
    <AppLayout title="Products">
        <PageHeader title="Products" description="Everything you sell. Stock shown is for the current branch.">
            <template #actions>
                <a
                    :href="route('products.export')"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                >
                    <ArrowDownTrayIcon class="h-4 w-4" /> Export
                </a>
                <ButtonLink v-if="can('products.manage')" :href="route('products.import')" variant="secondary">
                    <ArrowUpTrayIcon class="h-4 w-4" /> Import
                </ButtonLink>
                <ButtonLink v-if="can('products.manage')" :href="route('products.create')"><PlusIcon class="h-4 w-4" /> Add product</ButtonLink>
            </template>
        </PageHeader>

        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 md:grid-cols-4">
                <SearchInput v-model="filters.search" class="md:col-span-1" placeholder="Name, article no., SKU or barcode…" />
                <SelectInput v-model="filters.category_id" :options="categories" placeholder="All categories" />
                <SelectInput v-model="filters.brand_id" :options="brands" placeholder="All brands" />
                <SelectInput
                    v-model="filters.status"
                    :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]"
                    placeholder="Any status"
                />
            </div>

            <div v-if="selected.size" class="flex items-center justify-between gap-3 border-b border-indigo-100 bg-indigo-50 px-4 py-2 text-sm">
                <span class="text-indigo-800">{{ selected.size }} selected</span>
                <div class="flex gap-2">
                    <button class="text-indigo-700 hover:underline" @click="selected = new Set()">Clear</button>
                    <button
                        class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500"
                        @click="printLabels"
                    >
                        <PrinterIcon class="h-4 w-4" /> Print labels
                    </button>
                </div>
            </div>

            <DataTable :columns="columns" :rows="products.data" empty="No products found.">
                <template #select="{ row }">
                    <input
                        type="checkbox"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        :checked="selected.has(row.id)"
                        @change="toggle(row.id)"
                    />
                </template>
                <template #name="{ row }">
                    <Link :href="route('products.show', row.id)" class="flex items-center gap-3">
                        <img v-if="row.image_url" :src="row.image_url" alt="" class="h-10 w-10 rounded object-cover" />
                        <span v-else class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 text-gray-300"><PhotoIcon class="h-5 w-5" /></span>
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-gray-900 hover:text-indigo-700">{{ row.name }}</span>
                            <span class="block truncate text-xs text-gray-500">
                                <template v-if="row.type === 'variable'">{{ row.variant_count }} variants</template>
                                <template v-else>{{ row.sku }}<span v-if="row.barcode"> · {{ row.barcode }}</span></template>
                                <span v-if="row.article_no"> · Art. {{ row.article_no }}</span>
                                <span v-if="row.brand"> · {{ row.brand }}</span>
                            </span>
                        </span>
                    </Link>
                </template>
                <template #category="{ row }">{{ row.category ?? '—' }}</template>
                <template #gst="{ row }"><span :title="row.gst.includes('/') ? 'Depends on selling price per piece' : ''">{{ row.gst }}</span></template>
                <template #price="{ row }"><span class="whitespace-nowrap">{{ priceRange(row) }}</span></template>
                <template #stock="{ row }">
                    <span v-if="!row.track_stock" class="text-xs text-gray-400">Not tracked</span>
                    <span v-else :class="row.stock <= 0 ? 'font-medium text-red-600' : 'text-gray-900'">{{ qty(row.stock) }} {{ row.unit }}</span>
                </template>
                <template #is_active="{ row }">
                    <Badge :color="row.is_active ? 'green' : 'gray'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
                </template>
            </DataTable>

            <Pagination :paginator="products" />
        </Card>
    </AppLayout>
</template>
