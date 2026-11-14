<script setup>
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { dateTime, money } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import { ref, watch } from 'vue';

const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'recall', 'count']);
const held = ref([]);
const loading = ref(false);

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        loading.value = true;
        held.value = (await axios.get(route('pos.held.index'))).data.data;
        loading.value = false;
    },
);

const remove = async (h) => {
    const { data } = await axios.delete(route('pos.held.destroy', h.id));
    held.value = held.value.filter((x) => x.id !== h.id);
    emit('count', data.count);
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="p-6">
            <h3 class="mb-3 text-base font-semibold text-gray-900">Held bills</h3>
            <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
            <ul v-else class="divide-y divide-gray-100">
                <li v-for="h in held" :key="h.id" class="flex items-center gap-3 py-2">
                    <button type="button" class="flex-1 text-left hover:text-indigo-700" @click="emit('recall', h)">
                        <p class="text-sm font-medium">{{ h.label }}</p>
                        <p class="text-xs text-gray-500">{{ h.items }} items · {{ money(h.total) }} · {{ h.user }} · {{ dateTime(h.created_at) }}</p>
                    </button>
                    <button type="button" class="p-1 text-gray-400 hover:text-red-600" title="Discard" @click="remove(h)"><TrashIcon class="h-4 w-4" /></button>
                </li>
                <li v-if="!held.length" class="py-6 text-center text-sm text-gray-500">No held bills.</li>
            </ul>
            <div class="mt-4 text-right"><SecondaryButton @click="emit('close')">Close</SecondaryButton></div>
        </div>
    </Modal>
</template>
