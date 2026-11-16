<script setup>
import Card from '@/Components/Card.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/utils/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import Toggle from '@/Components/Toggle.vue';
import { realtimeConnected } from '@/echo';
import { desktopAlerts, desktopSupported, setDesktopAlerts } from '@/utils/desktopAlerts';
import { setSound, soundSwitch } from '@/utils/sounds';
import { ref } from 'vue';

const alertSound = soundSwitch('alerts', true);
const desktopNote = ref('');
const toggleDesktop = async (on) => {
    const ok = await setDesktopAlerts(on);
    desktopNote.value = on && !ok ? 'The browser blocked notifications — allow them in the site settings (padlock icon).' : '';
};

const props = defineProps({
    notifications: { type: Object, required: true },
    unreadOnly: { type: Boolean, default: false },
    unreadCount: { type: Number, default: 0 },
    types: { type: Array, required: true },
    emailEnabled: { type: Boolean, default: false },
});

const prefs = useForm({
    app: Object.fromEntries(props.types.map((t) => [t.key, t.app])),
    email: Object.fromEntries(props.types.map((t) => [t.key, t.email])),
});
const savePrefs = () =>
    prefs.transform((d) => ({
        off: Object.keys(d.app).filter((k) => !d.app[k]),
        email: Object.keys(d.email).filter((k) => d.email[k] && d.app[k]),
    })).put(route('notifications.preferences'), { preserveScroll: true });

const post = (name, id) => router.post(route(name, id), {}, { preserveScroll: true });
const dot = { danger: 'bg-red-500', warning: 'bg-amber-500', success: 'bg-green-500', info: 'bg-indigo-500' };
</script>

<template>
    <AppLayout title="Notifications">
        <PageHeader title="Notifications" :description="`${unreadCount} unread`">
            <template #actions>
                <SecondaryButton v-if="unreadCount" type="button" @click="router.post(route('notifications.read-all'), {}, { preserveScroll: true })">Mark all read</SecondaryButton>
                <SecondaryButton type="button" @click="router.delete(route('notifications.clear-read'), { preserveScroll: true })">Clear read</SecondaryButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <Card :padded="false" class="xl:col-span-2">
                <div class="flex gap-1 border-b border-gray-100 p-3 text-sm">
                    <Link :href="route('notifications.index')" class="rounded-sm px-3 py-1" :class="!unreadOnly ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-100'">All</Link>
                    <Link :href="route('notifications.index', { unread: 1 })" class="rounded-sm px-3 py-1" :class="unreadOnly ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-100'">Unread</Link>
                </div>
                <ul class="divide-y divide-gray-100">
                    <li v-for="n in notifications.data" :key="n.id" class="flex gap-3 px-5 py-3.5" :class="n.read ? 'bg-white' : 'bg-indigo-50/40'">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="n.read ? 'bg-gray-300' : dot[n.level] ?? dot.info" :title="n.level" />
                        <div class="min-w-0 flex-1">
                            <a :href="route('notifications.open', n.id)" class="text-sm hover:text-indigo-700" :class="n.read ? 'text-gray-700' : 'font-semibold text-gray-900'">{{ n.title }}</a>
                            <p class="text-sm text-gray-600">{{ n.body }}</p>
                            <p class="mt-0.5 text-xs text-gray-400">{{ dateTime(n.created_at) }}<template v-if="n.branch"> · {{ n.branch }}</template></p>
                        </div>
                        <button type="button" class="shrink-0 self-start text-xs text-gray-500 hover:text-indigo-700" @click="post('notifications.toggle', n.id)">
                            {{ n.read ? 'Mark unread' : 'Mark read' }}
                        </button>
                    </li>
                    <li v-if="!notifications.data.length" class="px-5 py-12 text-center text-sm text-gray-500">{{ unreadOnly ? 'All caught up.' : 'No notifications yet.' }}</li>
                </ul>
                <Pagination :paginator="notifications" />
            </Card>

            <div class="space-y-6">
            <Card title="On this device" description="How alerts reach you in this browser.">
                <div class="space-y-4">
                    <p class="flex items-center gap-2 text-sm">
                        <span class="h-2.5 w-2.5 rounded-full" :class="realtimeConnected ? 'bg-green-500' : 'bg-gray-300'" />
                        <span v-if="realtimeConnected" class="text-green-800">Live — new alerts appear instantly.</span>
                        <span v-else class="text-gray-600">Not live — checking every minute. (The real-time server isn't running or can't be reached.)</span>
                    </p>
                    <Toggle :model-value="alertSound" label="Play a sound for new alerts" @update:model-value="setSound('alerts', $event)" />
                    <Toggle v-if="desktopSupported()" :model-value="desktopAlerts" label="Desktop notifications" description="Pop up from the computer when this tab is in the background." @update:model-value="toggleDesktop" />
                    <p v-if="desktopNote" class="text-xs text-amber-700">{{ desktopNote }}</p>
                    <SecondaryButton type="button" @click="router.post(route('notifications.test'), {}, { preserveScroll: true })">Send me a test alert</SecondaryButton>
                </div>
            </Card>

            <Card title="Which alerts I get" description="Only alerts your role can receive are listed.">
                <form class="space-y-4" @submit.prevent="savePrefs">
                    <div v-for="t in types" :key="t.key" class="border-b border-gray-100 pb-3 last:border-0">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ t.label }}</p>
                                <p class="text-xs text-gray-500">{{ t.description }}</p>
                            </div>
                            <label class="flex shrink-0 items-center gap-1.5 text-xs text-gray-700">
                                <input v-model="prefs.app[t.key]" type="checkbox" class="rounded-sm border-gray-300 text-indigo-600" /> In app
                            </label>
                        </div>
                        <label v-if="emailEnabled" class="mt-1 flex items-center gap-1.5 text-xs text-gray-600" :class="!prefs.app[t.key] ? 'opacity-40' : ''">
                            <input v-model="prefs.email[t.key]" :disabled="!prefs.app[t.key]" type="checkbox" class="rounded-sm border-gray-300 text-indigo-600" /> Also email me
                        </label>
                    </div>
                    <p v-if="!types.length" class="text-sm text-gray-500">Your role doesn't receive any alerts.</p>
                    <p v-if="!emailEnabled && types.length" class="text-xs text-gray-500">Email alerts are switched off in Settings → Business.</p>
                    <div v-if="types.length" class="flex justify-end"><PrimaryButton :disabled="prefs.processing">Save</PrimaryButton></div>
                </form>
            </Card>
            </div>
        </div>
    </AppLayout>
</template>
