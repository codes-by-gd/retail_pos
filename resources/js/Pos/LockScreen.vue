<script setup>
import { LockClosedIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import { ref } from 'vue';
import { apiError } from './helpers';

const props = defineProps({ users: { type: Array, default: () => [] }, currentUserId: { type: Number, required: true } });
const emit = defineEmits(['unlocked']);
const userId = ref(props.currentUserId);
const pin = ref('');
const error = ref('');
const busy = ref(false);

const press = (d) => pin.value.length < 6 && (pin.value += d);
const unlock = async () => {
    error.value = '';
    busy.value = true;
    try {
        const { data } = await axios.post(route('pos.unlock'), { user_id: userId.value, pin: pin.value });
        emit('unlocked', data.user);
    } catch (e) {
        error.value = apiError(e);
        pin.value = '';
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/90 p-4" @keydown.enter="unlock">
        <div class="w-full max-w-xs rounded-xl bg-white p-6 text-center shadow-xl">
            <LockClosedIcon class="mx-auto h-10 w-10 text-indigo-600" />
            <h2 class="mt-2 text-lg font-semibold">POS locked</h2>
            <p v-if="!users.length" class="mt-3 text-sm text-gray-500">No cashier has a PIN set. Set PINs under Users.</p>
            <template v-else>
                <select v-model="userId" class="mt-4 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <input v-model="pin" type="password" inputmode="numeric" maxlength="6" placeholder="PIN" autofocus
                    class="mt-3 block w-full rounded-md border-gray-300 text-center text-2xl tracking-[0.5em] focus:border-indigo-500 focus:ring-indigo-500" />
                <div class="mt-3 grid grid-cols-3 gap-2">
                    <button v-for="d in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="d" type="button" class="rounded-lg border border-gray-200 py-3 text-lg hover:bg-gray-50" @click="press(d)">{{ d }}</button>
                    <button type="button" class="rounded-lg border border-gray-200 py-3 text-sm hover:bg-gray-50" @click="pin = ''">Clear</button>
                    <button type="button" class="rounded-lg border border-gray-200 py-3 text-lg hover:bg-gray-50" @click="press('0')">0</button>
                    <button type="button" class="rounded-lg bg-indigo-600 py-3 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50" :disabled="pin.length < 4 || busy" @click="unlock">Unlock</button>
                </div>
                <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
            </template>
        </div>
    </div>
</template>
