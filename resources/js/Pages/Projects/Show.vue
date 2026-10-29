<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';
import TaskAttachments from '@/Components/TaskAttachments.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMegabytes } from '@/lib/format';
import type { AttachmentRow, PageProps, Paginated } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface TaskRow { id: number; title: string; status: string; attachments: AttachmentRow[] }

const props = defineProps<{
    project: { id: number; name: string; description: string | null };
    tasks: Paginated<TaskRow>;
    filters: { q: string; status: string };
    statuses: string[];
    storage: { used_mb: number; limit_mb: number | null; max_file_kb: number; extensions: string[] };
    can: { delete: boolean };
}>();

const { t } = useI18n();

const canExport = computed(() => usePage<PageProps>().props.auth.features?.task_export === true);

// Which tasks have their file list open.
const openFiles = ref<Set<number>>(new Set());
const toggleFiles = (id: number) => (openFiles.value.has(id) ? openFiles.value.delete(id) : openFiles.value.add(id));

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
const removeProject = () => window.confirm(t('projectShow.confirmDelete', { name: props.project.name })) && router.delete(route('projects.destroy', props.project.id));
</script>

<template>
    <Head :title="project.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ project.name }}</h2>
                <div class="flex items-center gap-3">
                    <!-- A plain link: the response is a file download, not an Inertia page. -->
                    <a v-if="canExport" :href="route('projects.tasks.export', project.id)" class="text-sm text-indigo-700 dark:text-indigo-400 underline">{{ t('projectShow.exportCsv') }}</a>
                    <Link v-else :href="route('billing.show')" class="text-sm text-gray-500 dark:text-gray-400 underline" :title="t('projectShow.exportTitle')">{{ t('projectShow.exportCsvPro') }}</Link>
                    <Link :href="route('projects.edit', project.id)" class="text-sm text-indigo-700 dark:text-indigo-400 underline">{{ t('common.edit') }}</Link>
                    <DangerButton v-if="can.delete" @click="removeProject">{{ t('common.delete') }}</DangerButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-4 py-12 sm:px-6 lg:px-8">
            <p v-if="project.description" class="text-gray-600 dark:text-gray-400">{{ project.description }}</p>

            <form class="flex gap-3 bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg" @submit.prevent="add">
                <TextInput v-model="newTask.title" :placeholder="t('projectShow.newTaskTitle')" :aria-label="t('projectShow.newTaskTitle')" class="grow" required />
                <PrimaryButton :disabled="newTask.processing">{{ t('projectShow.addTask') }}</PrimaryButton>
            </form>
            <InputError :message="newTask.errors.title" />

            <div class="flex gap-3">
                <TextInput v-model="search" type="search" :placeholder="t('projectShow.searchTasks')" :aria-label="t('projectShow.searchTasks')" class="grow" />
                <select v-model="status" :aria-label="t('projectShow.filterStatus')" class="rounded-md border-gray-300 dark:border-gray-600 text-sm">
                    <option value="">{{ t('projectShow.allStatuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`status.${s}`) }}</option>
                </select>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <ul v-if="tasks.data.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="task in tasks.data" :key="task.id">
                      <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <span :class="{ 'text-gray-600 line-through dark:text-gray-400': task.status === 'done' }">{{ task.title }}</span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="text-sm text-indigo-700 hover:underline dark:text-indigo-400"
                                :aria-expanded="openFiles.has(task.id)"
                                :aria-label="t('projectShow.filesFor', { title: task.title })"
                                @click="toggleFiles(task.id)"
                            >
                                {{ t('projectShow.files', { count: task.attachments.length }) }}
                            </button>
                            <select :value="task.status" class="rounded-md border-gray-300 dark:border-gray-600 py-1 text-sm" @change="setStatus(task, ($event.target as HTMLSelectElement).value)">
                                <option v-for="s in statuses" :key="s" :value="s">{{ t(`status.${s}`) }}</option>
                            </select>
                            <button class="text-sm text-red-600 dark:text-red-400 hover:underline" @click="removeTask(task)">{{ t('common.delete') }}</button>
                        </div>
                      </div>
                      <TaskAttachments
                          v-if="openFiles.has(task.id)"
                          :project-id="project.id"
                          :task-id="task.id"
                          :attachments="task.attachments"
                          :max-file-kb="storage.max_file_kb"
                          :extensions="storage.extensions"
                      />
                    </li>
                </ul>
                <p v-else class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ t('projectShow.noTasks') }}</p>
            </div>

            <p class="text-xs text-gray-600 dark:text-gray-400" data-testid="storage-used">
                {{ t('projectShow.storage', { used: formatMegabytes(storage.used_mb, t('common.unlimited')), limit: formatMegabytes(storage.limit_mb, t('common.unlimited')) }) }}
            </p>

            <Pagination :page="tasks" />
        </div>
    </AuthenticatedLayout>
</template>
