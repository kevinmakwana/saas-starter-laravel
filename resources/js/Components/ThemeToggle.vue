<script setup lang="ts">
import { useTheme } from '@/composables/useTheme';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { isDark, toggle } = useTheme();
</script>

<template>
    <button
        type="button"
        :aria-label="isDark ? t('theme.toLight') : t('theme.toDark')"
        class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 dark:text-gray-400 transition hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        @click="toggle"
    >
        <svg v-if="isDark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="4" />
            <path stroke-linecap="round" d="M12 3v2m0 14v2M3 12h2m14 0h2m-3.5-6.5-1.4 1.4m-9.2 9.2-1.4 1.4m0-12 1.4 1.4m9.2 9.2 1.4 1.4" />
        </svg>
        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5a8.5 8.5 0 1 0 10.7 10.7Z" />
        </svg>
    </button>
</template>
