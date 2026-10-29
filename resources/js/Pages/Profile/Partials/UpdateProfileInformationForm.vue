<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps<{
    mustVerifyEmail?: Boolean;
    status?: String;
    pendingEmail?: string | null;
}>();

const { t } = useI18n();

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    current_password: '',
});

// A new address needs the password and is only applied once its owner opens the mailed link.
const changingEmail = computed(() => form.email.trim().toLowerCase() !== user.email.toLowerCase());

const cancelEmailChange = () => router.delete(route('profile.email.cancel'), { preserveScroll: true });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ t('profile.info.title') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ t('profile.info.intro') }}
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'), { preserveScroll: true, onSuccess: () => form.reset('current_password') })"
            class="mt-6 space-y-6"
        >
            <div>
                <InputLabel for="name" :value="t('profile.info.name')" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" :value="t('profile.info.email')" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="changingEmail">
                <InputLabel for="current_password" :value="t('profile.info.currentPassword')" />

                <TextInput
                    id="current_password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.current_password"
                    required
                    autocomplete="current-password"
                />

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ t('profile.info.emailHint') }}
                </p>

                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div v-if="pendingEmail" class="rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-sm text-amber-800 dark:text-amber-200" data-testid="pending-email">
                <i18n-t keypath="profile.info.pending" tag="span"><template #email><strong>{{ pendingEmail }}</strong></template></i18n-t>
                <button type="button" class="ml-1 underline hover:text-amber-950 dark:hover:text-amber-100" @click="cancelEmailChange">{{ t('profile.info.cancelChange') }}</button>
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-gray-800 dark:text-gray-200">
                    {{ t('profile.info.unverified') }}
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-gray-600 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        {{ t('profile.info.resend') }}
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600 dark:text-green-400"
                >
                    {{ t('profile.info.linkSent') }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ t('profile.save') }}</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-gray-600 dark:text-gray-400"
                    >
                        {{ t('profile.saved') }}
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
