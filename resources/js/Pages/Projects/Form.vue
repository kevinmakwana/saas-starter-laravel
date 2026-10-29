<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps<{ project: { id: number; name: string; description: string | null } | null }>();

const { t } = useI18n();

const form = useForm({ name: props.project?.name ?? '', description: props.project?.description ?? '' });

const submit = () =>
    props.project ? form.put(route('projects.update', props.project.id)) : form.post(route('projects.store'));
</script>

<template>
    <Head :title="project ? t('projects.edit') : t('projects.new')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ project ? t('projects.edit') : t('projects.new') }}</h2>
        </template>

        <div class="py-12">
            <form class="mx-auto max-w-xl space-y-4 bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                <div>
                    <InputLabel for="name" :value="t('common.name')" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="description" :value="t('common.description')" />
                    <textarea id="description" v-model="form.description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm" />
                    <InputError class="mt-2" :message="form.errors.description" />
                </div>
                <div class="flex items-center gap-3">
                    <PrimaryButton :disabled="form.processing">{{ t('common.save') }}</PrimaryButton>
                    <Link :href="route('projects.index')" class="text-sm text-gray-600 dark:text-gray-400 underline">{{ t('common.cancel') }}</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
