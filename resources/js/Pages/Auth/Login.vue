<script setup lang="ts">
import SocialLoginButtons from '@/Components/SocialLoginButtons.vue';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import type { SocialProvider } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
    socialProviders?: SocialProvider[];
}>();

const { t } = useI18n();

// A sign-in that failed at Google or GitHub comes back as a fresh page, not as an answer to this form.
const providerError = usePage().props.errors?.email;

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.logIn')" />

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
            {{ status }}
        </div>

        <SocialLoginButtons :providers="socialProviders ?? []" mode="signIn" />

        <p v-if="providerError && !form.errors.email" class="mb-4 text-sm text-red-600 dark:text-red-400" role="alert">{{ providerError }}</p>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" :value="t('auth.email')" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" :value="t('auth.password')" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400"
                        >{{ t('auth.rememberMe') }}</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-sm text-gray-600 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    {{ t('auth.forgotLink') }}
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t('auth.logIn') }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
