<script setup>
import { play } from '@/utils/sounds';
import { useCartStore } from '@/stores/cart';
import { money, qty } from '@/utils/format';
import { MinusIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { computed } from 'vue';

const props = defineProps({ item: { type: Object, required: true } });
defineEmits(['edit']);

const cart = useCartStore();
const calc = computed(() => cart.lineByKey(props.item.key));
const price = computed(() => cart.priceOf(props.item));
const list = computed(() => cart.listPriceOf(props.item));
const step = computed(() => (props.item.allow_decimal ? 0.25 : 1));
const discounted = computed(() => (calc.value?.line_discount ?? 0) > 0);
const overStock = computed(() => props.item.track_stock && Number(props.item.quantity) > props.item.stock);

const change = (delta) => {
    const next = Math.round((Number(props.item.quantity) + delta) * 1000) / 1000;
    if (next > 0) props.item.quantity = next;
};
</script>

<template>
    <div class="group flex items-start gap-3 border-b border-gray-100 px-4 py-2.5 hover:bg-gray-50">
        <button type="button" class="min-w-0 flex-1 text-left" title="Edit price / discount" @click="$emit('edit', item)">
            <p class="truncate text-sm font-medium text-gray-900">{{ item.product_name }}</p>
            <p class="truncate text-xs text-gray-500">
                <span v-if="item.variant_name" class="font-medium text-gray-700">{{ item.variant_name }}</span>
                <span v-if="item.variant_name"> · </span>{{ item.sku }}
            </p>
            <p class="text-xs">
                <span :class="price !== list ? 'text-amber-700' : 'text-gray-500'">{{ money(price) }}</span>
                <span v-if="price !== list" class="ml-1 text-gray-400 line-through">{{ money(list) }}</span>
                <span v-if="discounted" class="ml-1 rounded-sm bg-green-50 px-1 text-green-700">
                    −{{ item.discount_type === 'percent' ? `${Number(item.discount_value)}%` : money(item.discount_value) }}
                </span>
                <span class="ml-1 text-gray-400">· GST {{ calc?.gst_rate }}%</span>
                <span v-if="overStock" class="ml-1 text-red-600">· only {{ qty(item.stock) }} in stock</span>
            </p>
        </button>

        <div class="flex items-center rounded-md border border-gray-300">
            <button type="button" class="px-1.5 py-1 text-gray-500 hover:text-gray-900" @click="change(-step)"><MinusIcon class="h-4 w-4" /></button>
            <input
                v-model.number="item.quantity"
                type="number"
                :step="item.allow_decimal ? '0.001' : '1'"
                min="0"
                class="w-14 border-0 p-0 text-center text-sm focus:ring-0"
                @focus="$event.target.select()"
            />
            <button type="button" class="px-1.5 py-1 text-gray-500 hover:text-gray-900" @click="change(step)"><PlusIcon class="h-4 w-4" /></button>
        </div>

        <p class="w-24 shrink-0 pt-1 text-right text-sm font-semibold tabular-nums text-gray-900">{{ money(calc?.line_total ?? 0) }}</p>
        <button type="button" class="pt-1 text-gray-300 hover:text-red-600" title="Remove" @click="cart.remove(item.key); play('remove')">
            <XMarkIcon class="h-5 w-5" />
        </button>
    </div>
</template>
