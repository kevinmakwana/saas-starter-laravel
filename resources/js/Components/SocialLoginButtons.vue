<script setup lang="ts">
import type { SocialProvider } from '@/types';

defineProps<{ providers: SocialProvider[]; verb: string }>();
</script>

<template>
    <div v-if="providers.length" class="mb-6" data-testid="social-login">
        <div class="space-y-2">
            <!-- A plain link, not an Inertia visit: the answer is a redirect to another site. -->
            <a
                v-for="provider in providers"
                :key="provider.key"
                :href="route('social.redirect', provider.key)"
                class="flex w-full items-center justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                {{ verb }} with {{ provider.label }}
            </a>
        </div>
        <p class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">or use your email</p>
    </div>
</template>
