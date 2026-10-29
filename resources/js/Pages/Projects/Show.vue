<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import type { PageProps, Paginated } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface TaskRow { id: number; title: string; status: string }

const props = defineProps<{
    project: { id: number; name: string; description: string | null };
    tasks: Paginated<TaskRow>;
    filters: { q: string; status: string };
    statuses: string[];
    can: { delete: boolean };
}>();

const canExport = computed(() => usePage<PageProps>().props.auth.features?.task_export === true);

const search = ref(props.filters.q);
const status = ref(props.filters.status);
const newTask = useForm({ title: '' });

let timer: ReturnType<typeof setTimeout>;
watch([search, status], () => {
    clearTimeout(timer);
    timer = setTimeout(
        () => router.get(route('projects.show', props.project.id), { q: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }),
        250,
    );
});

const add = () => newTask.post(route('projects.tasks.store', props.project.id), { preserveScroll: true, onSuccess: () => newTask.reset() });
const setStatus = (task: TaskRow, value: string) =>
    router.put(route('projects.tasks.update', [props.project.id, task.id]), { title: task.title, status: value }, { preserveScroll: true });
const removeTask = (task: TaskRow) => router.delete(route('projects.tasks.destroy', [props.project.id, task.id]), { preserveScroll: true });
const removeProject = () => window.confirm(`Delete “${props.project.name}” and its tasks?`) && router.delete(route('projects.destroy', props.project.id));
</script>

<template>
    <Head :title="project.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ project.name }}</h2>
                <div class="flex items-center gap-3">
                    <!-- A plain link: the response is a file download, not an Inertia page. -->
                    <a v-if="canExport" :href="route('projects.tasks.export', project.id)" class="text-sm text-indigo-700 dark:text-indigo-400 underline">Export CSV</a>
                    <Link v-else :href="route('billing.show')" class="text-sm text-gray-500 dark:text-gray-400 underline" title="Export is included in Pro and Business">Export CSV (Pro)</Link>
                    <Link :href="route('projects.edit', project.id)" class="text-sm text-indigo-700 dark:text-indigo-400 underline">Edit</Link>
                    <DangerButton v-if="can.delete" @click="removeProject">Delete</DangerButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-4 py-12 sm:px-6 lg:px-8">
            <p v-if="project.description" class="text-gray-600 dark:text-gray-400">{{ project.description }}</p>

            <form class="flex gap-3 bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg" @submit.prevent="add">
                <TextInput v-model="newTask.title" placeholder="New task title" aria-label="New task title" class="grow" required />
                <PrimaryButton :disabled="newTask.processing">Add task</PrimaryButton>
            </form>
            <InputError :message="newTask.errors.title" />

            <div class="flex gap-3">
                <TextInput v-model="search" type="search" placeholder="Search tasks…" aria-label="Search tasks" class="grow" />
                <select v-model="status" aria-label="Filter by status" class="rounded-md border-gray-300 dark:border-gray-600 text-sm">
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <ul v-if="tasks.data.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="task in tasks.data" :key="task.id" class="flex items-center justify-between gap-3 p-4">
                        <span :class="{ 'text-gray-400 line-through': task.status === 'done' }">{{ task.title }}</span>
                        <div class="flex items-center gap-2">
                            <select :value="task.status" class="rounded-md border-gray-300 dark:border-gray-600 py-1 text-sm" @change="setStatus(task, ($event.target as HTMLSelectElement).value)">
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <button class="text-sm text-red-600 dark:text-red-400 hover:underline" @click="removeTask(task)">Delete</button>
                        </div>
                    </li>
                </ul>
                <p v-else class="p-6 text-sm text-gray-500 dark:text-gray-400">No tasks found.</p>
            </div>

            <Pagination :page="tasks" />
        </div>
    </AuthenticatedLayout>
</template>
