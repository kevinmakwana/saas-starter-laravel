<script setup lang="ts">
import type { ConnectedAccount } from '@/types';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<{ accounts: ConnectedAccount[] }>();

const { t } = useI18n();

const disconnect = (account: ConnectedAccount) =>
    window.confirm(t('profile.connected.confirm', { provider: account.provider })) &&
    router.delete(route('profile.social-accounts.destroy', account.id), { preserveScroll: true });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ t('profile.connected.title') }}</h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ t('profile.connected.intro') }}
            </p>
        </header>

        <ul class="mt-6 divide-y divide-gray-200 dark:divide-gray-700 text-sm" data-testid="connected-accounts">
            <li v-for="account in accounts" :key="account.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ account.provider }}</p>
                    <p class="break-all text-gray-600 dark:text-gray-400">{{ account.email }}</p>
                </div>
                <button type="button" class="text-red-600 dark:text-red-400 hover:underline" :aria-label="t('profile.connected.aria', { provider: account.provider })" @click="disconnect(account)">
                    {{ t('profile.connected.disconnect') }}
                </button>
            </li>
        </ul>
    </section>
</template>
