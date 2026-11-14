<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-indigo-50 via-white to-gray-100 px-4">
        <div class="mb-6 flex items-center gap-2">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white">P</span>
            <span class="text-xl font-semibold text-gray-900">{{ $page.props.app?.business_name ?? 'Retail POS' }}</span>
        </div>
        <div class="w-full overflow-hidden rounded-xl border border-gray-200 bg-white px-6 py-6 shadow-sm sm:max-w-md">
            <slot />
        </div>
    </div>
</template>
