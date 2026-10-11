<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import TextInput from '@/Components/TextInput.vue';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface ProjectRow { id: number; name: string; description: string | null; tasks_count: number }

const props = defineProps<{
    projects: Paginated<ProjectRow>;
    filters: { q: string };
    can: { create: boolean };
}>();

const search = ref(props.filters.q);
let timer: ReturnType<typeof setTimeout>;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('projects.index'), { q: value || undefined }, { preserveState: true, replace: true }), 250);
});
</script>

<template>
    <Head title="Projects" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Projects</h2>
                <Link
                    :href="route('projects.create')"
                    class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                >
                    New project
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 py-12 sm:px-6 lg:px-8">
            <TextInput v-model="search" type="search" placeholder="Search projects…" aria-label="Search projects" class="block w-full" />

            <div class="bg-white shadow sm:rounded-lg">
                <ul v-if="projects.data.length" class="divide-y divide-gray-100">
                    <li v-for="project in projects.data" :key="project.id" class="flex items-center justify-between p-4">
                        <div>
                            <Link :href="route('projects.show', project.id)" class="font-medium text-indigo-700 hover:underline">{{ project.name }}</Link>
                            <p v-if="project.description" class="text-sm text-gray-500">{{ project.description }}</p>
                        </div>
                        <span class="text-sm text-gray-500">{{ project.tasks_count }} tasks</span>
                    </li>
                </ul>
                <p v-else class="p-6 text-sm text-gray-500">No projects found.</p>
            </div>

            <Pagination :page="projects" />
        </div>
    </AuthenticatedLayout>
</template>
