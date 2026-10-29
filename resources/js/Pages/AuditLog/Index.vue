<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import type { Paginated } from '@/types';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

interface Entry { id: number; action: string; summary: string; actor: string; at: string }

defineProps<{ entries: Paginated<Entry> }>();
</script>

<template>
    <Head :title="t('auditLog.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ t('auditLog.title') }}</h2>
                <a :href="route('audit-log.export')" class="text-sm text-indigo-700 dark:text-indigo-400 underline">{{ t('auditLog.exportCsv') }}</a>
            </div>
        </template>

        <div class="mx-auto max-w-4xl py-12 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <ul v-if="entries.data.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="entry in entries.data" :key="entry.id" class="p-4">
                        <p class="text-gray-900 dark:text-gray-100"><span class="font-medium">{{ entry.actor }}</span> · {{ entry.summary }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ entry.action }} · {{ new Date(entry.at).toLocaleString(locale) }}</p>
                    </li>
                </ul>
                <p v-else class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ t('auditLog.empty') }}</p>
            </div>
            <p class="mt-4 text-xs text-gray-600 dark:text-gray-400">{{ t('auditLog.note') }}</p>
            <Pagination :page="entries" />
        </div>
    </AuthenticatedLayout>
</template>
