<script setup>
import Card from '@/Components/Card.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});
</script>

<template>
    <AppLayout title="My profile">
        <PageHeader title="My profile" />
        <div class="max-w-3xl space-y-6">
            <Card>
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" class="max-w-xl" />
            </Card>
            <Card>
                <UpdatePasswordForm class="max-w-xl" />
            </Card>
        </div>
    </AppLayout>
</template>
