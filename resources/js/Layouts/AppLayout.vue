<script setup>
import CommandPalette from '@/Components/CommandPalette.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useTheme } from '@/composables/useTheme';
import { navigation } from '@/navigation';
import {
    Bars3Icon,
    BuildingOffice2Icon,
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    ChevronDownIcon,
    ComputerDesktopIcon,
    MagnifyingGlassIcon,
    MoonIcon,
    SunIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref } from 'vue';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();
const { can } = usePermissions();
const { theme, setTheme } = useTheme();
const sidebarOpen = ref(false); // phones/tablets: drawer
const collapsed = useLocalStorage('pos-sidebar-collapsed', false); // desktop: icons only
const paletteOpen = ref(false);

const sections = computed(() =>
    navigation
        .map((section) => ({ ...section, items: section.items.filter((i) => can(i.permission)) }))
        .filter((section) => section.items.length),
);

const isActive = (item) => item.route && [item.active ?? item.route].flat().some((pattern) => route().current(pattern));

const branch = computed(() => page.props.branch);
const user = computed(() => page.props.auth.user);
const app = computed(() => page.props.app);

const switchBranch = (event) => {
    router.post(route('branch.switch'), { branch_id: Number(event.target.value) }, { preserveScroll: true });
};

const themes = [
    { value: 'light', label: 'Light', icon: SunIcon },
    { value: 'dark', label: 'Dark', icon: MoonIcon },
    { value: 'system', label: 'System', icon: ComputerDesktopIcon },
];
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

router.on('navigate', () => (sidebarOpen.value = false));
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-gray-50">
        <!-- Mobile backdrop -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" @click="sidebarOpen = false" />

        <!-- Sidebar -->
        <aside
            class="no-print fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-gray-200 bg-white transition-all duration-200 lg:translate-x-0"
            :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-16' : 'lg:w-64']"
        >
            <div class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-gray-100 px-4">
                <Link :href="route('dashboard')" class="flex min-w-0 items-center gap-2" :title="app.business_name">
                    <img v-if="app.logo_url && !collapsed" :src="app.logo_url" alt="" class="h-9 max-w-[7rem] shrink-0 object-contain" />
                    <span v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">
                        {{ (app.business_name || 'P').slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="truncate text-sm font-semibold text-gray-900" :class="collapsed ? 'lg:hidden' : ''">{{ app.business_name }}</span>
                </Link>
                <button class="text-gray-400 hover:text-gray-600 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto overflow-x-hidden py-4" :class="collapsed ? 'px-3 lg:px-2' : 'px-3'">
                <div v-for="(section, i) in sections" :key="i">
                    <p v-if="section.label" class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400" :class="collapsed ? 'lg:hidden' : ''">
                        {{ section.label }}
                    </p>
                    <div v-if="section.label && collapsed" class="mx-2 mb-2 hidden border-t border-gray-100 lg:block" />
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.label">
                            <span
                                v-if="item.soon"
                                class="flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-sm text-gray-400"
                                :title="`Coming in ${item.soon}`"
                            >
                                <component :is="item.icon" class="h-5 w-5 shrink-0" />
                                <span class="flex-1 truncate" :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
                            </span>
                            <Link
                                v-else
                                :href="route(item.route)"
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition"
                                :class="[isActive(item) ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-100', collapsed ? 'lg:justify-center lg:px-2' : '']"
                                :title="collapsed ? item.label : undefined"
                                :aria-current="isActive(item) ? 'page' : undefined"
                            >
                                <component :is="item.icon" class="h-5 w-5 shrink-0" :class="isActive(item) ? 'text-indigo-600' : 'text-gray-400'" />
                                <span class="truncate" :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <button
                type="button"
                class="hidden h-11 shrink-0 items-center gap-2 border-t border-gray-100 px-5 text-xs text-gray-500 hover:bg-gray-50 hover:text-gray-800 lg:flex"
                :class="collapsed ? 'justify-center px-0' : ''"
                :title="collapsed ? 'Expand menu' : 'Collapse menu'"
                @click="collapsed = !collapsed"
            >
                <component :is="collapsed ? ChevronDoubleRightIcon : ChevronDoubleLeftIcon" class="h-4 w-4" />
                <span v-if="!collapsed">Collapse menu</span>
            </button>
        </aside>

        <div class="transition-all duration-200" :class="collapsed ? 'lg:pl-16' : 'lg:pl-64'">
            <!-- Topbar -->
            <header class="no-print sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-gray-200 bg-white/90 px-3 backdrop-blur sm:gap-3 sm:px-6">
                <button class="rounded p-1 text-gray-500 hover:text-gray-700 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true">
                    <Bars3Icon class="h-6 w-6" />
                </button>

                <h1 class="min-w-0 flex-1 truncate text-base font-semibold text-gray-900">{{ title }}</h1>

                <button
                    type="button"
                    class="flex items-center gap-2 rounded-md border border-gray-200 px-2 py-1.5 text-sm text-gray-500 hover:border-gray-300 hover:text-gray-700 md:w-56 md:px-3"
                    aria-label="Search"
                    @click="paletteOpen = true"
                >
                    <MagnifyingGlassIcon class="h-4 w-4 shrink-0" />
                    <span class="hidden flex-1 text-left md:inline">Search…</span>
                    <kbd class="hidden rounded border border-gray-200 px-1 font-sans text-[10px] text-gray-400 md:inline">{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
                </button>

                <!-- Branch switcher -->
                <div v-if="branch?.current" class="flex min-w-0 items-center gap-1.5">
                    <BuildingOffice2Icon class="hidden h-5 w-5 text-gray-400 xl:block" />
                    <select
                        v-if="branch.available.length > 1"
                        :value="branch.current.id"
                        class="max-w-[9rem] rounded-md border-gray-300 py-1.5 pl-2 pr-8 text-sm focus:border-indigo-500 focus:ring-indigo-500 sm:max-w-none"
                        aria-label="Current branch"
                        @change="switchBranch"
                    >
                        <option v-for="b in branch.available" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <span v-else class="hidden truncate text-sm text-gray-700 sm:inline">{{ branch.current.name }}</span>
                </div>
                <span v-else class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-700">No branch</span>

                <NotificationBell />

                <Dropdown align="right" width="56">
                    <template #trigger>
                        <button class="flex items-center gap-2 rounded-md px-1 py-1.5 text-sm text-gray-700 hover:bg-gray-100 sm:px-2" aria-label="Account menu">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700">
                                {{ user.name.slice(0, 1).toUpperCase() }}
                            </span>
                            <span class="hidden text-left xl:block">
                                <span class="block font-medium leading-4">{{ user.name }}</span>
                                <span class="block text-xs text-gray-500">{{ user.roles.join(', ') }}</span>
                            </span>
                            <ChevronDownIcon class="hidden h-4 w-4 text-gray-400 sm:block" />
                        </button>
                    </template>
                    <template #content>
                        <div class="border-b border-gray-100 px-4 py-2 xl:hidden">
                            <p class="truncate text-sm font-medium text-gray-900">{{ user.name }}</p>
                            <p class="truncate text-xs text-gray-500">{{ user.roles.join(', ') }}</p>
                        </div>
                        <div class="border-b border-gray-100 px-3 py-2">
                            <p class="mb-1 px-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Theme</p>
                            <div class="grid grid-cols-3 gap-1">
                                <button
                                    v-for="t in themes"
                                    :key="t.value"
                                    type="button"
                                    class="flex flex-col items-center gap-0.5 rounded-md py-1.5 text-[11px]"
                                    :class="theme === t.value ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'"
                                    :aria-pressed="theme === t.value"
                                    @click.stop="setTheme(t.value)"
                                >
                                    <component :is="t.icon" class="h-4 w-4" />{{ t.label }}
                                </button>
                            </div>
                        </div>
                        <DropdownLink :href="route('profile.edit')">My profile</DropdownLink>
                        <DropdownLink :href="route('notifications.index')">Notifications & alerts</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">Log out</DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>

        <CommandPalette v-model:open="paletteOpen" />
        <FlashMessages />
    </div>
</template>
