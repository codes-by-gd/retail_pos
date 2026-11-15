<script setup>
/** Top-bar bell: unread badge, latest alerts on click, refreshed every minute. */
import { BellIcon } from '@heroicons/vue/24/outline';
import { Link, usePage } from '@inertiajs/vue3';
import { onClickOutside, useIntervalFn } from '@vueuse/core';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const page = usePage();
const unread = ref(page.props.notifications?.unread ?? 0);
const latest = ref([]);
const open = ref(false);
const loading = ref(false);
const root = ref(null);

watch(() => page.props.notifications?.unread, (n) => n !== undefined && (unread.value = n));

const load = async () => {
    try {
        loading.value = true;
        const { data } = await axios.get(route('notifications.summary'));
        unread.value = data.unread;
        latest.value = data.latest;
    } catch {
        // offline or logged out — keep the last numbers
    } finally {
        loading.value = false;
    }
};
useIntervalFn(() => document.visibilityState === 'visible' && load(), 60000);

const toggle = () => {
    open.value = !open.value;
    if (open.value) load();
};
onClickOutside(root, () => (open.value = false));

const readAll = async () => {
    await axios.post(route('notifications.read-all'), {}, { headers: { Accept: 'application/json' } });
    unread.value = 0;
    latest.value = latest.value.map((n) => ({ ...n, read: true }));
};

const dot = { danger: 'bg-red-500', warning: 'bg-amber-500', success: 'bg-green-500', info: 'bg-indigo-500' };
const ago = (iso) => {
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
};
const badge = computed(() => (unread.value > 99 ? '99+' : String(unread.value)));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative rounded-full p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
            :aria-label="`Notifications, ${unread} unread`"
            :aria-expanded="open"
            @click="toggle"
        >
            <BellIcon class="h-6 w-6" />
            <span v-if="unread" class="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-red-600 px-1 text-center text-[10px] font-semibold leading-[1.1rem] text-white">{{ badge }}</span>
        </button>

        <div v-if="open" class="fixed inset-x-4 top-16 z-40 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-[22rem]">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                <p class="text-sm font-semibold text-gray-900">Notifications</p>
                <button v-if="unread" type="button" class="text-xs text-indigo-600 hover:text-indigo-800" @click="readAll">Mark all read</button>
            </div>
            <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                <li v-for="n in latest" :key="n.id">
                    <a :href="route('notifications.open', n.id)" class="flex gap-3 px-4 py-3 hover:bg-gray-50" :class="n.read ? 'opacity-70' : ''">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-gray-300' : dot[n.level] ?? dot.info" />
                        <span class="min-w-0">
                            <span class="block text-sm" :class="n.read ? 'text-gray-700' : 'font-medium text-gray-900'">{{ n.title }}</span>
                            <span class="line-clamp-2 block text-xs text-gray-500">{{ n.body }}</span>
                            <span class="mt-0.5 block text-[11px] text-gray-400">{{ ago(n.created_at) }}<template v-if="n.branch"> · {{ n.branch }}</template></span>
                        </span>
                    </a>
                </li>
                <li v-if="!latest.length" class="px-4 py-8 text-center text-sm text-gray-500">{{ loading ? 'Loading…' : 'No notifications yet.' }}</li>
            </ul>
            <Link :href="route('notifications.index')" class="block border-t border-gray-100 px-4 py-2.5 text-center text-sm text-indigo-600 hover:bg-gray-50" @click="open = false">
                See all &amp; alert settings
            </Link>
        </div>
    </div>
</template>
