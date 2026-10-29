<script setup lang="ts">
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ canLogin?: boolean; canRegister?: boolean }>();
</script>

<template>
    <Head title="Acme" />

    <div class="relative flex min-h-screen flex-col items-center justify-center bg-gray-50 dark:bg-gray-900 px-6 text-center">
        <ThemeToggle class="absolute right-4 top-4" />
        <h1 class="text-4xl font-bold text-gray-900 dark:text-gray-100">Acme</h1>
        <p class="mt-3 max-w-md text-gray-600 dark:text-gray-400">
            A multi-tenant SaaS starter: teams, roles, plans and an audit log, ready to run locally with no paid keys.
        </p>
        <div class="mt-8 flex gap-3">
            <Link v-if="$page.props.auth.user" :href="route('dashboard')" class="rounded-md bg-gray-900 px-5 py-2 text-white dark:bg-gray-100 dark:text-gray-900">Dashboard</Link>
            <template v-else>
                <Link v-if="canLogin" :href="route('login')" class="rounded-md bg-gray-900 px-5 py-2 text-white dark:bg-gray-100 dark:text-gray-900">Log in</Link>
                <Link v-if="canRegister" :href="route('register')" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-5 py-2 text-gray-800 dark:text-gray-200">Register</Link>
            </template>
        </div>
    </div>
</template>
