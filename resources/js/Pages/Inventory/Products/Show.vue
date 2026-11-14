<script setup>
import Badge from '@/Components/Badge.vue';
import BarcodeSvg from '@/Components/BarcodeSvg.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, qty } from '@/utils/format';
import { PencilSquareIcon, PhotoIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    variants: { type: Array, required: true },
    branches: { type: Array, required: true },
    attributes: { type: Array, default: () => [] }, // attributes used, in order, with values
});

const { can } = usePermissions();
const currentBranchId = computed(() => usePage().props.branch?.current?.id);
const deleting = ref(false);

const totalStock = (v) => Object.values(v.stock).reduce((a, b) => a + b, 0);

// Stock matrix (e.g. Colour × Size) for one branch or all branches.
const matrixBranch = ref(currentBranchId.value ?? 'all');
const stockOf = (v) => (matrixBranch.value === 'all' ? totalStock(v) : v.stock[matrixBranch.value] ?? 0);
const rowsAttr = computed(() => (props.attributes.length === 2 ? props.attributes[0] : null));
const colsAttr = computed(() => props.attributes[props.attributes.length - 1]);
const cell = (rowValue, colValue) =>
    props.variants.find(
        (v) => (!rowsAttr.value || v.options[rowsAttr.value.id] === rowValue) && v.options[colsAttr.value.id] === colValue,
    );
const matrixRows = computed(() => (rowsAttr.value ? rowsAttr.value.values : [null]));
const rowTotal = (r) => colsAttr.value.values.reduce((s, c) => s + (cell(r, c) ? stockOf(cell(r, c)) : 0), 0);
const colTotal = (c) => matrixRows.value.reduce((s, r) => s + (cell(r, c) ? stockOf(cell(r, c)) : 0), 0);
const grandTotal = computed(() => props.variants.reduce((s, v) => s + stockOf(v), 0));
const labelsUrl = computed(() => route('labels.index', { variants: props.variants.map((v) => v.id) }));
const destroy = () => router.delete(route('products.destroy', props.product.id));
</script>

<template>
    <AppLayout :title="product.name">
        <PageHeader :title="product.name">
            <template #actions>
                <ButtonLink v-if="can('labels.print')" :href="labelsUrl" variant="secondary"><PrinterIcon class="h-4 w-4" /> Labels</ButtonLink>
                <DangerButton v-if="can('products.delete')" @click="deleting = true">Delete</DangerButton>
                <ButtonLink v-if="can('products.manage')" :href="route('products.edit', product.id)"><PencilSquareIcon class="h-4 w-4" /> Edit</ButtonLink>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Details">
                <div class="flex flex-col gap-6 sm:flex-row">
                    <img v-if="product.image_url" :src="product.image_url" alt="" class="h-32 w-32 rounded-md border object-cover" />
                    <div v-else class="flex h-32 w-32 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-300">
                        <PhotoIcon class="h-10 w-10" />
                    </div>
                    <dl class="grid flex-1 grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div><dt class="text-gray-500">Article no.</dt><dd class="font-mono font-medium">{{ product.article_no ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Fabric</dt><dd class="font-medium">{{ product.material ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Category</dt><dd class="font-medium">{{ product.category ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Brand</dt><dd class="font-medium">{{ product.brand ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Unit</dt><dd class="font-medium">{{ product.unit.name }} ({{ product.unit.short_name }})</dd></div>
                        <div><dt class="text-gray-500">HSN</dt><dd class="font-medium">{{ product.hsn_code ?? '—' }}</dd></div>
                        <div>
                            <dt class="text-gray-500">GST</dt>
                            <dd class="font-medium">
                                {{ product.tax_rate.label }}<span v-if="Number(product.tax_rate.cess_rate)"> + cess {{ Number(product.tax_rate.cess_rate) }}%</span>
                                <span class="block text-xs font-normal text-gray-500">({{ product.price_includes_tax ? 'included in price' : 'added to price' }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Status</dt>
                            <dd class="flex gap-1">
                                <Badge :color="product.is_active ? 'green' : 'gray'">{{ product.is_active ? 'Active' : 'Inactive' }}</Badge>
                                <Badge v-if="!product.track_stock" color="amber">Stock not tracked</Badge>
                            </dd>
                        </div>
                        <div v-if="product.description" class="col-span-2">
                            <dt class="text-gray-500">Description</dt>
                            <dd class="whitespace-pre-line">{{ product.description }}</dd>
                        </div>
                    </dl>
                </div>
            </Card>

            <Card v-if="product.track_stock" title="Stock by branch">
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="b in branches" :key="b.id" class="border-b border-gray-100 last:border-0">
                            <td class="py-2" :class="b.id === currentBranchId ? 'font-semibold text-indigo-700' : ''">{{ b.name }}</td>
                            <td class="py-2 text-right tabular-nums">
                                {{ qty(variants.reduce((sum, v) => sum + (v.stock[b.id] ?? 0), 0)) }} {{ product.unit.short_name }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <Card v-if="product.track_stock && attributes.length && attributes.length <= 2" class="mt-6" title="Stock matrix" :padded="false">
            <template #actions>
                <select v-model="matrixBranch" class="rounded-md border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="all">All branches</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </template>
            <div class="overflow-x-auto">
                <table class="min-w-full text-center text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-gray-500">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ rowsAttr ? `${rowsAttr.name} × ${colsAttr.name}` : colsAttr.name }}</th>
                            <th v-for="c in colsAttr.values" :key="c" class="px-3 py-2">{{ c }}</th>
                            <th class="px-4 py-2">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in matrixRows" :key="r ?? 'all'">
                            <td class="px-4 py-2 text-left font-medium text-gray-900">{{ r ?? 'Stock' }}</td>
                            <td v-for="c in colsAttr.values" :key="c" class="px-3 py-2 tabular-nums">
                                <span v-if="!cell(r, c)" class="text-gray-300">·</span>
                                <span
                                    v-else
                                    class="inline-block min-w-[2.5rem] rounded px-1.5 py-0.5"
                                    :class="stockOf(cell(r, c)) <= 0 ? 'bg-red-50 text-red-700' : stockOf(cell(r, c)) <= cell(r, c).reorder_level ? 'bg-amber-50 text-amber-800' : 'text-gray-900'"
                                    >{{ qty(stockOf(cell(r, c))) }}</span
                                >
                            </td>
                            <td class="px-4 py-2 font-semibold tabular-nums">{{ qty(rowTotal(r)) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rowsAttr" class="border-t border-gray-200 bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2 text-left">Total</td>
                            <td v-for="c in colsAttr.values" :key="c" class="px-3 py-2 tabular-nums">{{ qty(colTotal(c)) }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ qty(grandTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500">Red = out of stock · amber = at or below reorder level.</p>
        </Card>

        <Card class="mt-6" :title="product.type === 'variable' ? `Variants (${variants.length})` : 'Pricing'" :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th v-if="product.type === 'variable'" class="px-4 py-3 text-left">Variant</th>
                            <th class="px-4 py-3 text-left">SKU / Barcode</th>
                            <th class="px-4 py-3 text-right">Purchase</th>
                            <th class="px-4 py-3 text-right">MRP</th>
                            <th class="px-4 py-3 text-right">Selling</th>
                            <th class="px-4 py-3 text-right">Wholesale</th>
                            <th v-if="product.track_stock" class="px-4 py-3 text-right">Avg cost here</th>
                            <th v-if="product.track_stock" class="px-4 py-3 text-right">Stock (all)</th>
                            <th v-if="product.track_stock" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="v in variants" :key="v.id">
                            <td v-if="product.type === 'variable'" class="px-4 py-3 font-medium">
                                {{ v.name }} <Badge v-if="!v.is_active" color="gray">Inactive</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-mono text-xs">{{ v.sku }}</p>
                                <div v-for="code in v.barcodes" :key="code" class="mt-1 w-40"><BarcodeSvg :value="code" :height="28" :font-size="10" /></div>
                            </td>
                            <td class="px-4 py-3 text-right">{{ money(v.purchase_price) }}</td>
                            <td class="px-4 py-3 text-right">{{ v.mrp ? money(v.mrp) : '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium">{{ money(v.selling_price) }}</td>
                            <td class="px-4 py-3 text-right">{{ v.wholesale_price ? money(v.wholesale_price) : '—' }}</td>
                            <td v-if="product.track_stock" class="px-4 py-3 text-right">{{ v.avg_cost ? money(v.avg_cost) : '—' }}</td>
                            <td v-if="product.track_stock" class="px-4 py-3 text-right tabular-nums" :class="totalStock(v) <= 0 ? 'text-red-600' : ''">
                                {{ qty(totalStock(v)) }}
                            </td>
                            <td v-if="product.track_stock" class="px-4 py-3 text-right">
                                <Link :href="route('stock.ledger', { variant_id: v.id })" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">History</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <ConfirmModal
            :show="deleting"
            title="Delete product?"
            :message="`“${product.name}” will be removed from the catalog. Its stock history is kept.`"
            @confirm="destroy"
            @close="deleting = false"
        />
    </AppLayout>
</template>
