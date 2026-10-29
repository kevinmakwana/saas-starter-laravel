<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ApiTokensForm from './Partials/ApiTokensForm.vue';
import ConnectedAccountsForm from './Partials/ConnectedAccountsForm.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import ExportDataForm from './Partials/ExportDataForm.vue';
import TwoFactorForm from './Partials/TwoFactorForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import type { ApiToken, ConnectedAccount } from '@/types';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
    pendingEmail?: string | null;
    twoFactor: {
        enabled: boolean;
        pending: boolean;
        setupKey: string | null;
        qrSvg: string | null;
        recoveryCodesLeft: number | null;
    };
    recoveryCodes?: string[] | null;
    apiTokens: ApiToken[];
    apiTeams: { id: number; name: string }[];
    newToken?: string | null;
    socialAccounts: ConnectedAccount[];
}>();
</script>

<template>
    <Head :title="t('nav.profile')" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
            >
                {{ t('nav.profile') }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        :pending-email="pendingEmail"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    v-if="socialAccounts.length"
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <ConnectedAccountsForm
                        :accounts="socialAccounts"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <TwoFactorForm
                        :two-factor="twoFactor"
                        :recovery-codes="recoveryCodes"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <ApiTokensForm
                        :tokens="apiTokens"
                        :teams="apiTeams"
                        :new-token="newToken"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <ExportDataForm class="max-w-xl" />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
