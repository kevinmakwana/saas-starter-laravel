<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';
import { useI18n } from 'vue-i18n';

defineProps<{ page: Paginated<unknown> }>();

const { t } = useI18n();
</script>

<template>
    <nav v-if="page.last_page > 1" class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm" :aria-label="t('pagination.nav')">
        <p class="text-gray-600 dark:text-gray-400">{{ t('pagination.showing', { from: page.from, to: page.to, total: page.total }) }}</p>
        <div class="flex flex-wrap gap-1">
            <template v-for="link in page.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded border px-3 py-1"
                    :class="link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'"
                    v-html="link.label"
                />
                <span v-else aria-disabled="true" class="cursor-not-allowed rounded border border-gray-200 px-3 py-1 text-gray-600 dark:border-gray-700 dark:text-gray-500" v-html="link.label" />
            </template>
        </div>
    </nav>
</template>
