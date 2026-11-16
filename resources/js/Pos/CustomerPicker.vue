<script setup>
import Badge from '@/Components/Badge.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useCartStore } from '@/stores/cart';
import { money } from '@/utils/format';
import { UserCircleIcon, UserPlusIcon } from '@heroicons/vue/24/outline';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { nextTick, reactive, ref } from 'vue';
import { apiError } from './helpers';

const props = defineProps({ walkIn: { type: Object, required: true } });
const { can } = usePermissions();
const cart = useCartStore();

const open = ref(false);
const term = ref('');
const results = ref([]);
const searching = ref(false);
const searchInput = ref(null);
const adding = ref(false);
const newCustomer = reactive({ name: '', phone: '', gstin: '' });
const error = ref('');

const show = async () => {
    open.value = true;
    term.value = '';
    results.value = [];
    adding.value = false;
    error.value = '';
    await nextTick();
    searchInput.value?.focus();
};
defineExpose({ show });

const search = useDebounceFn(async () => {
    if (!term.value.trim()) return (results.value = []);
    searching.value = true;
    try {
        results.value = (await axios.get(route('contacts.search'), { params: { q: term.value, kind: 'customer' } })).data.data;
    } finally {
        searching.value = false;
    }
}, 200);

const choose = (c) => {
    cart.setCustomer(c);
    open.value = false;
};
const walkIn = () => choose({ ...props.walkIn, is_walk_in: true, price_type: 'retail', discount_percent: 0, balance: 0, credit_limit: 0, state_code: null });

const startAdd = () => {
    adding.value = true;
    const digits = term.value.replace(/\D/g, '');
    Object.assign(newCustomer, { name: digits.length >= 6 ? '' : term.value, phone: digits.length >= 6 ? term.value : '', gstin: '' });
};
const save = async () => {
    error.value = '';
    try {
        const { data } = await axios.post(route('contacts.quick-store'), newCustomer);
        choose(data.data);
    } catch (e) {
        error.value = apiError(e);
    }
};
</script>

<template>
    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-gray-50" @click="show">
        <UserCircleIcon class="h-9 w-9 shrink-0 text-gray-300" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900">{{ cart.customer?.name }}</p>
            <p class="truncate text-xs text-gray-500">
                <template v-if="cart.customer?.is_walk_in">Walk-in · press F4 to add a customer</template>
                <template v-else>
                    {{ cart.customer?.phone }}
                    <span v-if="cart.customer?.balance > 0" class="text-amber-700"> · owes {{ money(cart.customer.balance) }}</span>
                    <span v-else-if="cart.customer?.balance < 0" class="text-indigo-700"> · credit {{ money(-cart.customer.balance) }}</span>
                </template>
            </p>
        </div>
        <Badge v-if="cart.customer?.price_type === 'wholesale'" color="amber">Wholesale</Badge>
        <Badge v-if="cart.customer?.discount_percent > 0" color="green">{{ cart.customer.discount_percent }}% member</Badge>
        <Badge v-if="!cart.customer?.is_walk_in && cart.customer?.points > 0" color="amber" :title="`${cart.customer.points} loyalty points`">★ {{ cart.customer.points }} pts</Badge>
        <Badge v-if="cart.interState" color="indigo">IGST</Badge>
        <span class="text-xs font-medium text-indigo-600">Change</span>
    </button>

    <Modal :show="open" max-width="lg" @close="open = false">
        <div class="p-6">
            <template v-if="!adding">
                <h3 class="mb-3 text-base font-semibold text-gray-900">Customer</h3>
                <input
                    ref="searchInput"
                    v-model="term"
                    type="text"
                    placeholder="Mobile number or name…"
                    class="block w-full rounded-md border-gray-300 py-2.5 text-base shadow-xs focus:border-indigo-500 focus:ring-indigo-500"
                    @input="search"
                    @keydown.enter.prevent="results[0] ? choose(results[0]) : can('customers.manage') && term && startAdd()"
                />
                <ul class="mt-3 max-h-72 divide-y divide-gray-100 overflow-y-auto">
                    <li v-for="c in results" :key="c.id">
                        <button type="button" class="flex w-full items-center justify-between gap-3 px-2 py-2 text-left hover:bg-indigo-50" @click="choose(c)">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">{{ c.name }}<span v-if="c.company_name" class="text-gray-500"> · {{ c.company_name }}</span></span>
                                <span class="block text-xs text-gray-500">{{ c.phone }} · {{ c.code }}</span>
                            </span>
                            <span class="text-right text-xs">
                                <span v-if="c.balance > 0" class="block text-amber-700">Owes {{ money(c.balance) }}</span>
                                <span v-else-if="c.balance < 0" class="block text-indigo-700">Credit {{ money(-c.balance) }}</span>
                                <span v-if="c.discount_percent" class="block text-green-700">{{ c.discount_percent }}% off</span>
                                <span v-if="c.points" class="block text-amber-700">★ {{ c.points }} pts</span>
                            </span>
                        </button>
                    </li>
                    <li v-if="term && !results.length && !searching" class="px-2 py-3 text-sm text-gray-500">No customer found.</li>
                </ul>
                <div class="mt-4 flex justify-between gap-2">
                    <SecondaryButton @click="walkIn">Walk-in customer</SecondaryButton>
                    <PrimaryButton v-if="can('customers.manage')" type="button" @click="startAdd"><UserPlusIcon class="h-4 w-4" /> New customer</PrimaryButton>
                </div>
            </template>

            <form v-else class="space-y-4" @submit.prevent="save">
                <h3 class="text-base font-semibold text-gray-900">New customer</h3>
                <FormField label="Mobile number" required><TextInput v-model="newCustomer.phone" type="tel" autofocus /></FormField>
                <FormField label="Name" required><TextInput v-model="newCustomer.name" /></FormField>
                <FormField label="GSTIN" hint="Only for business customers (tax invoice)."><TextInput v-model="newCustomer.gstin" maxlength="15" class="uppercase" /></FormField>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton @click="adding = false">Back</SecondaryButton>
                    <PrimaryButton>Save & use</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
