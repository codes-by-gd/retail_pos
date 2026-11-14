<script setup>
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';

const model = defineModel({ type: String, default: '' });
defineProps({ placeholder: { type: String, default: 'Search…' } });

// Local value updates instantly; the model (which usually triggers a server reload) is debounced.
const local = ref(model.value ?? '');
const commit = useDebounceFn((v) => (model.value = v), 300);
watch(local, commit);
watch(model, (v) => {
    if (v !== local.value) local.value = v ?? '';
});
</script>

<template>
    <div class="relative">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
        <input
            v-model="local"
            type="search"
            :placeholder="placeholder"
            class="block w-full rounded-md border-gray-300 pl-9 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
    </div>
</template>
