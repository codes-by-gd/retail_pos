<script setup>
import NotificationBell from '@/Components/NotificationBell.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { usePermissions } from '@/composables/usePermissions';
import { navigation } from '@/navigation';
import {
    Bars3Icon,
    BuildingOffice2Icon,
    ChevronDownIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();
const { can } = usePermissions();
const sidebarOpen = ref(false);

const sections = computed(() =>
    navigation
        .map((section) => ({ ...section, items: section.items.filter((i) => can(i.permission)) }))
        .filter((section) => section.items.length),
);

const isActive = (item) => item.route && [item.active ?? item.route].flat().some((pattern) => route().current(pattern));

const branch = computed(() => page.props.branch);
const user = computed(() => page.props.auth.user);

const switchBranch = (event) => {
    router.post(route('branch.switch'), { branch_id: Number(event.target.value) }, { preserveScroll: true });
};

router.on('navigate', () => (sidebarOpen.value = false));
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-gray-50">
        <!-- Mobile backdrop -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" @click="sidebarOpen = false" />

        <!-- Sidebar -->
        <aside
            class="no-print fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-gray-200 bg-white transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-gray-100 px-5">
                <Link :href="route('dashboard')" class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">P</span>
                    <span class="truncate text-sm font-semibold text-gray-900">{{ $page.props.app.business_name }}</span>
                </Link>
                <button class="text-gray-400 hover:text-gray-600 lg:hidden" @click="sidebarOpen = false">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
                <div v-for="(section, i) in sections" :key="i">
                    <p v-if="section.label" class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                        {{ section.label }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.label">
                            <span
                                v-if="item.soon"
                                class="flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-sm text-gray-400"
                                :title="`Coming in ${item.soon}`"
                            >
                                <component :is="item.icon" class="h-5 w-5 shrink-0" />
                                <span class="flex-1 truncate">{{ item.label }}</span>
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">Soon</span>
                            </span>
                            <Link
                                v-else
                                :href="route(item.route)"
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition"
                                :class="isActive(item) ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-100'"
                            >
                                <component
                                    :is="item.icon"
                                    class="h-5 w-5 shrink-0"
                                    :class="isActive(item) ? 'text-indigo-600' : 'text-gray-400'"
                                />
                                <span class="truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <div class="lg:pl-64">
            <!-- Topbar -->
            <header class="no-print sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur sm:px-6">
                <button class="text-gray-500 hover:text-gray-700 lg:hidden" @click="sidebarOpen = true">
                    <Bars3Icon class="h-6 w-6" />
                </button>

                <h1 class="min-w-0 flex-1 truncate text-base font-semibold text-gray-900">{{ title }}</h1>

                <!-- Branch switcher -->
                <div v-if="branch?.current" class="flex items-center gap-1.5">
                    <BuildingOffice2Icon class="hidden h-5 w-5 text-gray-400 sm:block" />
                    <select
                        v-if="branch.available.length > 1"
                        :value="branch.current.id"
                        class="rounded-md border-gray-300 py-1.5 pl-2 pr-8 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        aria-label="Current branch"
                        @change="switchBranch"
                    >
                        <option v-for="b in branch.available" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <span v-else class="text-sm text-gray-700">{{ branch.current.name }}</span>
                </div>
                <span v-else class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-700">No branch assigned</span>

                <NotificationBell />

                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700">
                                {{ user.name.slice(0, 1).toUpperCase() }}
                            </span>
                            <span class="hidden text-left sm:block">
                                <span class="block font-medium leading-4">{{ user.name }}</span>
                                <span class="block text-xs text-gray-500">{{ user.roles.join(', ') }}</span>
                            </span>
                            <ChevronDownIcon class="h-4 w-4 text-gray-400" />
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">My profile</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">Log out</DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>

        <FlashMessages />
    </div>
</template>
