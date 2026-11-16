<script setup>
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/utils/format';
import { ArrowDownTrayIcon, CircleStackIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    backups: { type: Array, required: true },
    keepDays: { type: Number, required: true },
    driver: { type: String, required: true },
});

const creating = ref(false);
const create = () => router.post(route('settings.backups.store'), {}, { preserveScroll: true, onStart: () => (creating.value = true), onFinish: () => (creating.value = false) });
const deleting = ref(null);
const destroy = () => router.delete(route('settings.backups.destroy', deleting.value.name), { preserveScroll: true, onFinish: () => (deleting.value = null) });
const form = useForm({ keep_days: props.keepDays });
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
</script>

<template>
    <AppLayout title="Backups">
        <PageHeader title="Database backups" description="A complete copy of all data (sales, stock, contacts, settings). Keep a copy outside this server.">
            <template #actions>
                <PrimaryButton type="button" :disabled="creating" @click="create"><CircleStackIcon class="h-4 w-4" /> {{ creating ? 'Backing up…' : 'Back up now' }}</PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <Card :padded="false" class="xl:col-span-2" title="Backups on this server">
                <ul class="divide-y divide-gray-100">
                    <li v-for="b in backups" :key="b.name" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm text-gray-900">{{ b.name }}</p>
                            <p class="text-xs text-gray-500">{{ dateTime(b.created_at) }} · {{ size(b.size) }}</p>
                        </div>
                        <div class="flex gap-2">
                            <a :href="route('settings.backups.download', b.name)"><SecondaryButton type="button"><ArrowDownTrayIcon class="h-4 w-4" /> Download</SecondaryButton></a>
                            <button type="button" class="rounded-sm p-2 text-gray-400 hover:bg-gray-100 hover:text-red-600" title="Delete" @click="deleting = b"><TrashIcon class="h-4 w-4" /></button>
                        </div>
                    </li>
                    <li v-if="!backups.length" class="px-5 py-10 text-center text-sm text-gray-500">No backups yet. Click “Back up now”.</li>
                </ul>
            </Card>

            <div class="space-y-6">
                <Card title="Automatic backups">
                    <p class="text-sm text-gray-600">A backup runs every night at 23:30 when the server's scheduler is set up (see the docs). Older backups are deleted automatically.</p>
                    <form class="mt-4 flex items-end gap-3" @submit.prevent="form.put(route('settings.backups.settings'), { preserveScroll: true })">
                        <FormField class="flex-1" label="Keep backups for (days)" :error="form.errors.keep_days"><TextInput v-model.number="form.keep_days" type="number" min="1" max="365" /></FormField>
                        <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                    </form>
                </Card>
                <Card title="Restoring">
                    <p class="text-sm text-gray-600">Restoring replaces <strong>all</strong> current data, so it is done by the system administrator on the server:</p>
                    <pre v-if="driver !== 'sqlite'" class="mt-2 overflow-x-auto rounded-sm bg-gray-900 p-3 text-xs text-gray-100">gunzip -c pos-….sql.gz | mysql -u root -p pos</pre>
                    <pre v-else class="mt-2 overflow-x-auto rounded-sm bg-gray-900 p-3 text-xs text-gray-100">gunzip -c pos-….sqlite.gz &gt; database/database.sqlite</pre>
                </Card>
            </div>
        </div>

        <ConfirmModal :show="!!deleting" title="Delete backup?" :message="`${deleting?.name} will be deleted from the server.`" @confirm="destroy" @close="deleting = null" />
    </AppLayout>
</template>
