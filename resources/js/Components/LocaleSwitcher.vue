<script setup lang="ts">
import type { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage<PageProps>();

// Saved on the account when signed in, and for this browser otherwise. The page comes back in the new language.
const change = (event: Event) =>
    router.put(route('locale.update'), { locale: (event.target as HTMLSelectElement).value }, { preserveScroll: true });
</script>

<template>
    <select
        :value="page.props.locale"
        :aria-label="t('language.label')"
        class="rounded-md border-gray-300 dark:border-gray-600 py-1.5 text-sm text-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        @change="change"
    >
        <option v-for="locale in page.props.locales" :key="locale.code" :value="locale.code" :lang="locale.code">{{ locale.name }}</option>
    </select>
</template>
