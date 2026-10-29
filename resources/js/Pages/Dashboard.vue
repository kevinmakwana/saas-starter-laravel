<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Usage { used: number; limit: number | null }

defineProps<{
    plan: { name: string; projects: Usage; members: Usage };
    taskCounts: Record<string, number>;
    recentProjects: { id: number; name: string }[];
}>();

const usage = (u: Usage) => `${u.used} / ${u.limit ?? '∞'}`;
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Dashboard</h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 py-12 sm:px-6 lg:px-8">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Plan</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ plan.name }}</p>
                    <Link :href="route('billing.show')" class="text-sm text-indigo-700 dark:text-indigo-400 underline">Manage billing</Link>
                </div>
                <div class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Projects</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ usage(plan.projects) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Members</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ usage(plan.members) }}</p>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                    <h3 class="mb-3 font-medium text-gray-900 dark:text-gray-100">Tasks</h3>
                    <dl class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <div v-for="status in ['todo', 'doing', 'done']" :key="status" class="flex justify-between">
                            <dt class="capitalize">{{ status }}</dt>
                            <dd>{{ taskCounts[status] ?? 0 }}</dd>
                        </div>
                    </dl>
                </section>
                <section class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                    <h3 class="mb-3 font-medium text-gray-900 dark:text-gray-100">Recent projects</h3>
                    <ul v-if="recentProjects.length" class="space-y-1 text-sm">
                        <li v-for="project in recentProjects" :key="project.id">
                            <Link :href="route('projects.show', project.id)" class="text-indigo-700 dark:text-indigo-400 hover:underline">{{ project.name }}</Link>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                        No projects yet. <Link :href="route('projects.create')" class="text-indigo-700 dark:text-indigo-400 underline">Create one</Link>.
                    </p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
