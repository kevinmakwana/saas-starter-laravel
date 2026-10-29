<script setup lang="ts">
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<{
    twoFactor: {
        enabled: boolean;
        pending: boolean;
        setupKey: string | null;
        qrSvg: string | null;
        recoveryCodesLeft: number | null;
    };
    // Sent once, by the request that made them.
    recoveryCodes?: string[] | null;
}>();

const { t } = useI18n();

const confirm = useForm({ code: '' });

// Each of these asks for the password first (the server redirects to the confirm-password page).
const start = () => router.post(route('profile.two-factor.store'), {}, { preserveScroll: true });
const regenerate = () => router.post(route('profile.two-factor.recovery-codes'), {}, { preserveScroll: true });
const turnOff = () => window.confirm(t('profile.twoFactor.confirmOff')) && router.delete(route('profile.two-factor.destroy'), { preserveScroll: true });

const submitConfirm = () => confirm.post(route('profile.two-factor.confirm'), { preserveScroll: true, onFinish: () => confirm.reset() });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ t('profile.twoFactor.title') }}</h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ t('profile.twoFactor.intro') }}
            </p>
        </header>

        <div v-if="recoveryCodes?.length" class="mt-6 rounded-md bg-amber-50 dark:bg-amber-900/30 p-4 text-sm text-amber-900 dark:text-amber-200" data-testid="recovery-codes">
            <p class="font-medium">{{ t('profile.twoFactor.saveCodes') }}</p>
            <p class="mt-1">{{ t('profile.twoFactor.codesOnce') }}</p>
            <ul class="mt-3 grid grid-cols-1 gap-1 font-mono sm:grid-cols-2">
                <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
            </ul>
        </div>

        <div v-if="twoFactor.enabled" class="mt-6 space-y-4">
            <p class="text-sm text-gray-800 dark:text-gray-200">
                <i18n-t keypath="profile.twoFactor.state" tag="span"><template #state><strong>{{ t('profile.twoFactor.on') }}</strong></template></i18n-t>
                {{ t('profile.twoFactor.codesLeft', twoFactor.recoveryCodesLeft ?? 0) }}
            </p>
            <div class="flex flex-wrap gap-3">
                <SecondaryButton @click="regenerate">{{ t('profile.twoFactor.regenerate') }}</SecondaryButton>
                <DangerButton @click="turnOff">{{ t('profile.twoFactor.turnOff') }}</DangerButton>
            </div>
        </div>

        <div v-else-if="twoFactor.pending" class="mt-6 space-y-4">
            <p class="text-sm text-gray-800 dark:text-gray-200">
                {{ t('profile.twoFactor.scan') }}
            </p>
            <div v-if="twoFactor.qrSvg" class="inline-block bg-white p-4" data-testid="qr-code" v-html="twoFactor.qrSvg" />
            <p class="font-mono text-sm break-all text-gray-700 dark:text-gray-300" data-testid="setup-key">{{ twoFactor.setupKey }}</p>

            <form class="space-y-4" @submit.prevent="submitConfirm">
                <div>
                    <InputLabel for="two_factor_code" :value="t('profile.twoFactor.code')" />
                    <TextInput
                        id="two_factor_code"
                        v-model="confirm.code"
                        type="text"
                        inputmode="numeric"
                        class="mt-1 block w-full max-w-xs"
                        autocomplete="one-time-code"
                        required
                    />
                    <InputError class="mt-2" :message="confirm.errors.code" />
                </div>
                <div class="flex gap-3">
                    <PrimaryButton :disabled="confirm.processing">{{ t('profile.twoFactor.turnOn') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="turnOff">{{ t('common.cancel') }}</SecondaryButton>
                </div>
            </form>
        </div>

        <div v-else class="mt-6">
            <PrimaryButton @click="start">{{ t('profile.twoFactor.setUp') }}</PrimaryButton>
        </div>
    </section>
</template>
