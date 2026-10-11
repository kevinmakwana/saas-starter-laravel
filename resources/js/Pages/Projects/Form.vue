<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ project: { id: number; name: string; description: string | null } | null }>();

const form = useForm({ name: props.project?.name ?? '', description: props.project?.description ?? '' });

const submit = () =>
    props.project ? form.put(route('projects.update', props.project.id)) : form.post(route('projects.store'));
</script>

<template>
    <Head :title="project ? 'Edit project' : 'New project'" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ project ? 'Edit project' : 'New project' }}</h2>
        </template>

        <div class="py-12">
            <form class="mx-auto max-w-xl space-y-4 bg-white p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="description" value="Description" />
                    <textarea id="description" v-model="form.description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" />
                    <InputError class="mt-2" :message="form.errors.description" />
                </div>
                <div class="flex items-center gap-3">
                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                    <Link :href="route('projects.index')" class="text-sm text-gray-600 underline">Cancel</Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
