<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ name: '' });
</script>

<template>
    <Head title="Create team" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Create a team</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl bg-white p-6 shadow sm:rounded-lg">
                <form class="space-y-4" @submit.prevent="form.post(route('teams.store'))">
                    <div>
                        <InputLabel for="name" value="Team name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>
                    <PrimaryButton :disabled="form.processing">Create team</PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
