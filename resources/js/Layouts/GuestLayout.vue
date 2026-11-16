<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-linear-to-br from-indigo-50 via-white to-gray-100 px-4">
        <div class="mb-6 flex items-center gap-2">
            <img v-if="$page.props.app?.logo_url" :src="$page.props.app.logo_url" alt="" class="h-12 max-w-[10rem] object-contain" />
            <span v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white">{{ ($page.props.app?.business_name ?? 'P').slice(0, 1) }}</span>
            <span class="text-xl font-semibold text-gray-900">{{ $page.props.app?.business_name ?? 'Retail POS' }}</span>
        </div>
        <div class="w-full overflow-hidden rounded-xl border border-gray-200 bg-white px-6 py-6 shadow-xs sm:max-w-md">
            <slot />
        </div>
    </div>
</template>
