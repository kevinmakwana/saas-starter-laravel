<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// One field is sent at a time: the app's code, or a recovery code for when the phone is not at hand.
const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

const submit = () => {
    form.post(route('two-factor.login'), {
        onFinish: () => form.reset(),
    });
};

const toggle = () => {
    useRecovery.value = !useRecovery.value;
    form.reset();
    form.clearErrors();
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-factor authentication" />

        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{
                useRecovery
                    ? 'Enter one of your recovery codes. Each one works only once.'
                    : 'Enter the 6-digit code from your authenticator app.'
            }}
        </p>

        <form @submit.prevent="submit">
            <div v-if="!useRecovery">
                <InputLabel for="code" value="Code" />
                <TextInput
                    id="code"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    class="mt-1 block w-full"
                    autocomplete="one-time-code"
                    autofocus
                    required
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Recovery code" />
                <TextInput
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    class="mt-1 block w-full"
                    autocomplete="off"
                    autofocus
                    required
                />
                <InputError class="mt-2" :message="form.errors.recovery_code" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    class="rounded-md text-sm text-gray-600 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    @click="toggle"
                >
                    {{ useRecovery ? 'Use an authenticator code' : 'Use a recovery code' }}
                </button>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
