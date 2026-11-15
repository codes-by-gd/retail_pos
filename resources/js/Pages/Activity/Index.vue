<script setup>
import { useListFilters } from '@/composables/useListFilters';
import Card from '@/Components/Card.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';

const props = defineProps({
    activities: { type: Object, required: true },
    filters: { type: Object, required: true },
    users: { type: Array, required: true },
    subjects: { type: Array, required: true },
});

const filters = useListFilters('activity.index', {
    search: props.filters.search ?? '', user_id: props.filters.user_id ? Number(props.filters.user_id) : null,
    subject: props.filters.subject ?? null, from: props.filters.from ?? '', to: props.filters.to ?? '',
});

const expanded = ref(new Set());
const toggle = (id) => (expanded.value.has(id) ? expanded.value.delete(id) : expanded.value.add(id));
const eventColor = { created: 'text-green-700', updated: 'text-indigo-700', deleted: 'text-red-700' };
</script>

<template>
    <AppLayout title="Audit log">
        <PageHeader title="Audit log" description="Who did what and when — price changes, cancellations, cash, payments, permissions, logins. Entries can't be edited." />

        <Card :padded="false">
            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-5">
                <SearchInput v-model="filters.search" placeholder="Search action or details…" />
                <SelectInput v-model="filters.user_id" :options="users" placeholder="All users" />
                <SelectInput v-model="filters.subject" :options="subjects" placeholder="Everything" />
                <TextInput v-model="filters.from" type="date" aria-label="From" />
                <TextInput v-model="filters.to" type="date" aria-label="To" />
            </div>

            <ul class="divide-y divide-gray-100">
                <li v-for="a in activities.data" :key="a.id" class="px-5 py-3">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-900">
                                <span class="font-medium">{{ a.user }}</span>
                                <span :class="eventColor[a.event] ?? 'text-gray-700'"> · {{ a.description }}</span>
                                <template v-if="a.subject">
                                    ·
                                    <Link v-if="a.url" :href="a.url" class="text-indigo-600 hover:text-indigo-800">{{ a.subject }}</Link>
                                    <span v-else class="text-gray-500">{{ a.subject }}</span>
                                </template>
                            </p>
                            <p v-if="a.details.length && !a.changes.length" class="mt-0.5 text-xs text-gray-500">
                                <span v-for="(d, i) in a.details" :key="d.field"><span v-if="i"> · </span>{{ d.field }}: <span class="text-gray-700">{{ d.value }}</span></span>
                            </p>
                            <button v-if="a.changes.length" type="button" class="mt-0.5 text-xs text-indigo-600 hover:text-indigo-800" @click="toggle(a.id)">
                                {{ expanded.has(a.id) ? 'Hide' : 'Show' }} {{ a.changes.length }} change{{ a.changes.length === 1 ? '' : 's' }}
                            </button>
                        </div>
                        <p class="shrink-0 text-xs text-gray-500">{{ dateTime(a.at) }}</p>
                    </div>
                    <div v-if="expanded.has(a.id)" class="mt-2 overflow-x-auto">
                        <table class="text-xs">
                            <thead class="text-left text-gray-500"><tr><th class="py-1 pr-4 font-medium">Field</th><th class="py-1 pr-4 font-medium">Before</th><th class="py-1 font-medium">After</th></tr></thead>
                            <tbody>
                                <tr v-for="c in a.changes" :key="c.field" class="border-t border-gray-100">
                                    <td class="py-1 pr-4 text-gray-600">{{ c.field }}</td>
                                    <td class="py-1 pr-4 text-red-700 line-through decoration-red-300">{{ c.old }}</td>
                                    <td class="py-1 text-green-800">{{ c.new }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </li>
                <li v-if="!activities.data.length" class="px-5 py-12 text-center text-sm text-gray-500">Nothing matches these filters.</li>
            </ul>
            <Pagination :paginator="activities" />
        </Card>
    </AppLayout>
</template>
