<script setup lang="ts">
import type { ConnectedAccount } from '@/types';
import { router } from '@inertiajs/vue3';

defineProps<{ accounts: ConnectedAccount[] }>();

const disconnect = (account: ConnectedAccount) =>
    window.confirm(`Disconnect ${account.provider}? You can still sign in with your password.`) &&
    router.delete(route('profile.social-accounts.destroy', account.id), { preserveScroll: true });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Connected accounts</h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                You can sign in with these. If you signed up with one and never chose a password, use “Forgot your password?” on the sign-in page to set
                one: it is asked for before sensitive changes.
            </p>
        </header>

        <ul class="mt-6 divide-y divide-gray-200 dark:divide-gray-700 text-sm" data-testid="connected-accounts">
            <li v-for="account in accounts" :key="account.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ account.provider }}</p>
                    <p class="break-all text-gray-600 dark:text-gray-400">{{ account.email }}</p>
                </div>
                <button type="button" class="text-red-600 dark:text-red-400 hover:underline" :aria-label="`Disconnect ${account.provider}`" @click="disconnect(account)">
                    Disconnect
                </button>
            </li>
        </ul>
    </section>
</template>
