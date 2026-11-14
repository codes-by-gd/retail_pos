<script setup>
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';

defineProps({ deletable: { type: Boolean, default: true } });
defineEmits(['edit', 'delete']);
</script>

<template>
    <div class="flex justify-end gap-1">
        <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-indigo-600" title="Edit" @click="$emit('edit')">
            <PencilSquareIcon class="h-4 w-4" />
        </button>
        <button v-if="deletable" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-600" title="Delete" @click="$emit('delete')">
            <TrashIcon class="h-4 w-4" />
        </button>
    </div>
</template>
