<script setup lang="ts">
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { router, useForm } from '@inertiajs/vue3';

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

const confirm = useForm({ code: '' });

// Each of these asks for the password first (the server redirects to the confirm-password page).
const start = () => router.post(route('profile.two-factor.store'), {}, { preserveScroll: true });
const regenerate = () => router.post(route('profile.two-factor.recovery-codes'), {}, { preserveScroll: true });
const turnOff = () => window.confirm('Turn off two-factor authentication?') && router.delete(route('profile.two-factor.destroy'), { preserveScroll: true });

const submitConfirm = () => confirm.post(route('profile.two-factor.confirm'), { preserveScroll: true, onFinish: () => confirm.reset() });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Two-factor authentication</h2>

            <p class="mt-1 text-sm text-gray-600">
                Ask for a code from an authenticator app when you sign in, on top of your password.
            </p>
        </header>

        <div v-if="recoveryCodes?.length" class="mt-6 rounded-md bg-amber-50 p-4 text-sm text-amber-900" data-testid="recovery-codes">
            <p class="font-medium">Save these recovery codes somewhere safe.</p>
            <p class="mt-1">Each works once if you lose your phone. They will not be shown again.</p>
            <ul class="mt-3 grid grid-cols-1 gap-1 font-mono sm:grid-cols-2">
                <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
            </ul>
        </div>

        <div v-if="twoFactor.enabled" class="mt-6 space-y-4">
            <p class="text-sm text-gray-800">
                Two-factor authentication is <strong>on</strong>. You have {{ twoFactor.recoveryCodesLeft }} recovery codes left.
            </p>
            <div class="flex flex-wrap gap-3">
                <SecondaryButton @click="regenerate">Make new recovery codes</SecondaryButton>
                <DangerButton @click="turnOff">Turn off</DangerButton>
            </div>
        </div>

        <div v-else-if="twoFactor.pending" class="mt-6 space-y-4">
            <p class="text-sm text-gray-800">
                Scan this code with your authenticator app, or type the key in by hand, then enter the 6-digit code it shows.
            </p>
            <div v-if="twoFactor.qrSvg" class="inline-block bg-white p-4" data-testid="qr-code" v-html="twoFactor.qrSvg" />
            <p class="font-mono text-sm break-all text-gray-700" data-testid="setup-key">{{ twoFactor.setupKey }}</p>

            <form class="space-y-4" @submit.prevent="submitConfirm">
                <div>
                    <InputLabel for="two_factor_code" value="Code" />
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
                    <PrimaryButton :disabled="confirm.processing">Turn on</PrimaryButton>
                    <SecondaryButton type="button" @click="turnOff">Cancel</SecondaryButton>
                </div>
            </form>
        </div>

        <div v-else class="mt-6">
            <PrimaryButton @click="start">Set up</PrimaryButton>
        </div>
    </section>
</template>
