<script setup>
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import AppLayout from '@/Layouts/AppLayout.vue';
import { CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    mail: { type: Object, required: true },
    encryptions: { type: Array, required: true },
    status: { type: Object, required: true },
    businessName: { type: String, default: '' },
    adminEmail: { type: String, default: '' },
});

const form = useForm({
    enabled: props.mail.enabled,
    host: props.mail.host ?? '',
    port: props.mail.port ?? 587,
    encryption: props.mail.encryption ?? 'tls',
    username: props.mail.username ?? '',
    password: '',
    remove_password: false,
    from_address: props.mail.from_address ?? '',
    from_name: props.mail.from_name ?? '',
});
const test = useForm({ to: props.adminEmail });

// sensible default port per encryption
watch(() => form.encryption, (e) => {
    if ([25, 465, 587].includes(Number(form.port))) form.port = { tls: 587, ssl: 465, none: 25 }[e];
});

const presets = [
    { label: 'Gmail / Google Workspace', host: 'smtp.gmail.com', port: 587, encryption: 'tls', hint: 'Use an App password (Google account → Security → 2-Step Verification → App passwords).' },
    { label: 'Microsoft 365 / Outlook', host: 'smtp.office365.com', port: 587, encryption: 'tls', hint: 'SMTP AUTH must be allowed for the mailbox.' },
    { label: 'Zoho Mail (India)', host: 'smtp.zoho.in', port: 465, encryption: 'ssl', hint: 'Use an app-specific password if 2FA is on.' },
    { label: 'Amazon SES (Mumbai)', host: 'email-smtp.ap-south-1.amazonaws.com', port: 587, encryption: 'tls', hint: 'Use the SES SMTP credentials, not your AWS login.' },
];
const usePreset = (p) => Object.assign(form, { host: p.host, port: p.port, encryption: p.encryption });
const hint = () => presets.find((p) => p.host === form.host)?.hint;

const save = () => form.put(route('settings.mail.update'), { preserveScroll: true, onSuccess: () => form.reset('password', 'remove_password') });
useUnsavedGuard(() => form.isDirty && !form.processing && !form.wasSuccessful);
</script>

<template>
    <AppLayout title="Email">
        <PageHeader title="Email (SMTP)" description="Used for “Forgot password”, welcome emails for new staff and email alerts." />

        <div class="mb-6 flex items-start gap-3 rounded-lg border p-4 text-sm" :class="status.ready ? 'border-green-200 bg-green-50 text-green-900' : 'border-amber-200 bg-amber-50 text-amber-900'">
            <component :is="status.ready ? CheckCircleIcon : ExclamationTriangleIcon" class="h-5 w-5 shrink-0" />
            <div>
                <p v-if="status.source === 'settings'" class="font-medium">Email is set up (settings below).</p>
                <p v-else-if="status.source === 'env'" class="font-medium">Email is set up in the server's .env file (mailer “{{ status.env_mailer }}”). Settings saved here take over when switched on.</p>
                <p v-else class="font-medium">Email is not set up — “Forgot password” is hidden on the login page and no emails are sent.</p>
                <p class="mt-0.5 text-xs opacity-80">After saving, send a test email to make sure it arrives.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <Card class="xl:col-span-2" title="SMTP account">
                <form class="space-y-5" @submit.prevent="save">
                    <Toggle v-model="form.enabled" label="Send email using these settings" description="Off = use the server's .env MAIL_* settings (if any)." />

                    <div>
                        <p class="mb-1.5 text-xs font-medium text-gray-500">Quick fill</p>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="p in presets" :key="p.host" type="button" class="rounded-md border border-gray-200 px-2.5 py-1 text-xs text-gray-700 hover:border-indigo-300 hover:text-indigo-700" @click="usePreset(p)">{{ p.label }}</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                        <FormField class="sm:col-span-3" label="SMTP server" :error="form.errors.host" :hint="hint()"><TextInput v-model="form.host" placeholder="smtp.example.com" autocomplete="off" /></FormField>
                        <FormField class="sm:col-span-2" label="Encryption" :error="form.errors.encryption"><SelectInput v-model="form.encryption" :options="encryptions" /></FormField>
                        <FormField class="sm:col-span-1" label="Port" :error="form.errors.port"><TextInput v-model.number="form.port" type="number" min="1" max="65535" /></FormField>
                        <FormField class="sm:col-span-3" label="Username" :error="form.errors.username"><TextInput v-model="form.username" autocomplete="off" placeholder="usually the full email address" /></FormField>
                        <FormField class="sm:col-span-3" label="Password" :error="form.errors.password" :hint="mail.has_password ? 'A password is saved (stored encrypted). Leave empty to keep it.' : 'Stored encrypted.'">
                            <TextInput v-model="form.password" type="password" autocomplete="new-password" :placeholder="mail.has_password ? '••••••••' : ''" />
                            <label v-if="mail.has_password" class="mt-1 flex items-center gap-2 text-xs text-gray-600"><input v-model="form.remove_password" type="checkbox" class="rounded-sm border-gray-300 text-indigo-600" /> Remove the saved password</label>
                        </FormField>
                        <FormField class="sm:col-span-3" label="From address" :error="form.errors.from_address" hint="Most providers only allow your own mailbox or a verified domain."><TextInput v-model="form.from_address" type="email" placeholder="billing@yourshop.in" /></FormField>
                        <FormField class="sm:col-span-3" label="From name" :error="form.errors.from_name"><TextInput v-model="form.from_name" :placeholder="businessName" /></FormField>
                    </div>

                    <div class="flex justify-end"><PrimaryButton :disabled="form.processing">Save email settings</PrimaryButton></div>
                </form>
            </Card>

            <div class="space-y-6">
                <Card title="Send a test email">
                    <form class="space-y-3" @submit.prevent="test.post(route('settings.mail.test'), { preserveScroll: true })">
                        <FormField label="Send to" :error="test.errors.to"><TextInput v-model="test.to" type="email" /></FormField>
                        <SecondaryButton type="submit" :disabled="test.processing || !status.ready">{{ test.processing ? 'Sending…' : 'Send test email' }}</SecondaryButton>
                        <p v-if="!status.ready" class="text-xs text-gray-500">Save working settings first.</p>
                    </form>
                </Card>
                <Card title="What uses email">
                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600">
                        <li>“Forgot your password?” on the login page</li>
                        <li>Welcome email with a set-password link for new staff (Users)</li>
                        <li>“Send password reset email” for a user (Users)</li>
                        <li>Email alerts — switch on in Settings → Business → Alerts; each person picks alerts on their Notifications page</li>
                    </ul>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
