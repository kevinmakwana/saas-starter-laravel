<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import TextInput from '@/Components/TextInput.vue';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface ProjectRow { id: number; name: string; description: string | null; tasks_count: number }

const props = defineProps<{
    projects: Paginated<ProjectRow>;
    filters: { q: string };
    can: { create: boolean };
}>();

const { t } = useI18n();

const search = ref(props.filters.q);
let timer: ReturnType<typeof setTimeout>;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('projects.index'), { q: value || undefined }, { preserveState: true, replace: true }), 250);
});
</script>

<template>
    <Head :title="t('projects.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ t('projects.title') }}</h2>
                <Link
                    :href="route('projects.create')"
                    class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-white"
                >
                    {{ t('projects.new') }}
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 py-12 sm:px-6 lg:px-8">
            <TextInput v-model="search" type="search" :placeholder="t('projects.search')" :aria-label="t('projects.search')" class="block w-full" />

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <ul v-if="projects.data.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="project in projects.data" :key="project.id" class="flex items-center justify-between p-4">
                        <div>
                            <Link :href="route('projects.show', project.id)" class="font-medium text-indigo-700 dark:text-indigo-400 hover:underline">{{ project.name }}</Link>
                            <p v-if="project.description" class="text-sm text-gray-500 dark:text-gray-400">{{ project.description }}</p>
                        </div>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ t('projects.tasksCount', project.tasks_count) }}</span>
                    </li>
                </ul>
                <p v-else class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ t('projects.empty') }}</p>
            </div>

            <Pagination :page="projects" />
        </div>
    </AuthenticatedLayout>
</template>
