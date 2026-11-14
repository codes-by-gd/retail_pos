<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    message: { type: String, default: '' },
    confirmText: { type: String, default: 'Delete' },
    processing: { type: Boolean, default: false },
});

defineEmits(['confirm', 'close']);
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <div class="p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ title }}</h3>
            <p v-if="message" class="mt-2 text-sm text-gray-600">{{ message }}</p>
            <div class="mt-6 flex justify-end gap-2">
                <SecondaryButton @click="$emit('close')">Cancel</SecondaryButton>
                <DangerButton :disabled="processing" :class="{ 'opacity-50': processing }" @click="$emit('confirm')">
                    {{ confirmText }}
                </DangerButton>
            </div>
        </div>
    </Modal>
</template>
