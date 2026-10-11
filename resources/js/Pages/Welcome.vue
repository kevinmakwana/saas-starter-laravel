<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ canLogin?: boolean; canRegister?: boolean }>();
</script>

<template>
    <Head title="Acme" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-6 text-center">
        <h1 class="text-4xl font-bold text-gray-900">Acme</h1>
        <p class="mt-3 max-w-md text-gray-600">
            A multi-tenant SaaS starter: teams, roles, plans and an audit log, ready to run locally with no paid keys.
        </p>
        <div class="mt-8 flex gap-3">
            <Link v-if="$page.props.auth.user" :href="route('dashboard')" class="rounded-md bg-gray-900 px-5 py-2 text-white">Dashboard</Link>
            <template v-else>
                <Link v-if="canLogin" :href="route('login')" class="rounded-md bg-gray-900 px-5 py-2 text-white">Log in</Link>
                <Link v-if="canRegister" :href="route('register')" class="rounded-md border border-gray-300 bg-white px-5 py-2 text-gray-800">Register</Link>
            </template>
        </div>
    </div>
</template>
