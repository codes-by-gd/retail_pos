<script setup>
/**
 * Ctrl+K / ⌘K: jump to any page, run a quick action, or find a product, customer, supplier, bill,
 * purchase or quotation. Teleported overlay (not <dialog>, which breaks barcode scanners).
 */
import { useTheme } from '@/composables/useTheme';
import { usePermissions } from '@/composables/usePermissions';
import { navigation } from '@/navigation';
import { ArrowRightIcon, BoltIcon, MagnifyingGlassIcon, MoonIcon } from '@heroicons/vue/24/outline';
import { router } from '@inertiajs/vue3';
import { onKeyStroke, useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';

const open = defineModel('open', { type: Boolean, default: false });
const { can } = usePermissions();
const { cycle, theme } = useTheme();

const query = ref('');
const remote = ref([]);
const loading = ref(false);
const active = ref(0);
const input = ref(null);
const list = ref(null);

const pages = computed(() =>
    navigation.flatMap((s) => s.items.filter((i) => i.route && can(i.permission)).map((i) => ({ group: 'Pages', title: i.label, sub: s.label, icon: i.icon, href: route(i.route) }))),
);
const actions = computed(() => [
    { title: 'New bill', sub: 'POS billing', perm: 'pos.access', href: route('pos.terminal') },
    { title: 'Add expense', perm: 'expenses.manage', href: route('expenses.index', { add: 1 }) },
    { title: 'New customer', perm: 'customers.manage', href: route('customers.create') },
    { title: 'New product', perm: 'products.manage', href: route('products.create') },
    { title: 'Receive goods (purchase)', perm: 'purchases.manage', href: route('purchases.create') },
    { title: 'New purchase order', perm: 'purchases.manage', href: route('purchase-orders.create') },
    { title: 'New quotation', perm: 'quotations.manage', href: route('quotations.create') },
    { title: `Theme: ${theme.value} (switch)`, run: cycle, icon: MoonIcon },
].filter((a) => !a.perm || can(a.perm)).map((a) => ({ group: 'Actions', icon: a.icon ?? BoltIcon, ...a })));

const match = (item, q) => `${item.title} ${item.sub ?? ''}`.toLowerCase().includes(q);
const items = computed(() => {
    const q = query.value.trim().toLowerCase();
    const local = [...actions.value, ...pages.value].filter((i) => !q || match(i, q));
    return [...(q ? local.slice(0, 8) : local), ...remote.value.map((r) => ({ ...r, href: r.url }))];
});
const grouped = computed(() => {
    const out = [];
    items.value.forEach((item, index) => {
        const last = out[out.length - 1];
        if (!last || last.group !== item.group) out.push({ group: item.group, rows: [] });
        out[out.length - 1].rows.push({ ...item, index });
    });
    return out;
});

const fetchRemote = useDebounceFn(async (q) => {
    if (q.trim().length < 2) {
        remote.value = [];
        loading.value = false;
        return;
    }
    try {
        const { data } = await axios.get(route('search'), { params: { q } });
        if (q === query.value) remote.value = data.results;
    } catch {
        remote.value = [];
    } finally {
        loading.value = false;
    }
}, 200);

watch(query, (q) => {
    active.value = 0;
    loading.value = q.trim().length >= 2;
    fetchRemote(q);
});
watch(open, async (v) => {
    if (!v) return;
    query.value = '';
    remote.value = [];
    active.value = 0;
    await nextTick();
    input.value?.focus();
});

const choose = (item) => {
    if (!item) return;
    open.value = false;
    if (item.run) item.run();
    else router.visit(item.href);
};
const move = async (d) => {
    const n = items.value.length;
    if (!n) return;
    active.value = (active.value + d + n) % n;
    await nextTick();
    list.value?.querySelector(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' });
};

onKeyStroke(['k', 'K'], (e) => {
    if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        open.value = !open.value;
    }
});
router.on('navigate', () => (open.value = false));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[60] flex items-start justify-center bg-gray-900/50 p-4 pt-[10vh]" @mousedown.self="open = false">
            <div class="w-full max-w-xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl" role="dialog" aria-label="Search">
                <div class="flex items-center gap-3 border-b border-gray-100 px-4">
                    <MagnifyingGlassIcon class="h-5 w-5 shrink-0 text-gray-400" />
                    <input
                        ref="input"
                        v-model="query"
                        type="text"
                        placeholder="Search pages, products, customers, bills…"
                        class="w-full border-0 bg-transparent py-4 text-base focus:ring-0"
                        aria-label="Search"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="choose(items[active])"
                        @keydown.esc.prevent="open = false"
                    />
                    <kbd class="hidden rounded-sm border border-gray-200 px-1.5 text-xs text-gray-400 sm:block">Esc</kbd>
                </div>
                <div ref="list" class="max-h-[60vh] overflow-y-auto py-2">
                    <div v-for="g in grouped" :key="g.group" class="mb-1">
                        <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ g.group }}</p>
                        <button
                            v-for="item in g.rows"
                            :key="`${g.group}-${item.index}`"
                            type="button"
                            :data-index="item.index"
                            class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm"
                            :class="item.index === active ? 'bg-indigo-50 text-indigo-900' : 'text-gray-700'"
                            @mousemove="active = item.index"
                            @click="choose(item)"
                        >
                            <component :is="item.icon ?? ArrowRightIcon" class="h-4 w-4 shrink-0" :class="item.index === active ? 'text-indigo-600' : 'text-gray-400'" />
                            <span class="min-w-0 flex-1 truncate">{{ item.title }}</span>
                            <span v-if="item.sub" class="max-w-[45%] shrink-0 truncate text-xs text-gray-500">{{ item.sub }}</span>
                        </button>
                    </div>
                    <p v-if="loading" class="px-4 py-3 text-sm text-gray-500">Searching…</p>
                    <p v-else-if="!items.length" class="px-4 py-8 text-center text-sm text-gray-500">Nothing found for “{{ query }}”.</p>
                </div>
                <div class="hidden items-center gap-4 border-t border-gray-100 px-4 py-2 text-xs text-gray-400 sm:flex">
                    <span><kbd class="font-sans">↑↓</kbd> move</span><span><kbd class="font-sans">Enter</kbd> open</span><span><kbd class="font-sans">Ctrl K</kbd> toggle</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
