<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { isAxiosError } from 'axios';
import { ref } from 'vue';

const password = ref('');
const error = ref('');
const processing = ref(false);
const done = ref(false);

// The answer is a file, so it is fetched as a blob and saved from here rather than navigated to.
// That also keeps what was typed when the password is wrong.
const download = async () => {
    processing.value = true;
    error.value = '';
    done.value = false;

    try {
        const response = await window.axios.post(route('profile.export'), { password: password.value }, { responseType: 'blob' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(response.data);
        link.download = 'account-data.json';
        link.click();
        URL.revokeObjectURL(link.href);
        password.value = '';
        done.value = true;
    } catch (e) {
        error.value = await describe(e);
    } finally {
        processing.value = false;
    }
};

const describe = async (e: unknown): Promise<string> => {
    if (!isAxiosError(e) || !e.response) {
        return 'Could not reach the server. Please try again.';
    }
    if (e.response.status === 429) {
        return 'You have downloaded your data too often. Please try again later.';
    }
    if (e.response.status === 422) {
        try {
            const body = JSON.parse(await (e.response.data as Blob).text());
            return body.errors?.password?.[0] ?? body.message;
        } catch {
            // fall through
        }
    }
    return 'Something went wrong. Please try again.';
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Download your data</h2>

            <p class="mt-1 text-sm text-gray-600">
                Get a copy of the personal data we hold about you as a JSON file: your account, your teams and roles, your
                invitations and the activity you caused. Team content such as projects and tasks belongs to the team and is
                not included.
            </p>
        </header>

        <form class="mt-6 space-y-6" @submit.prevent="download">
            <div>
                <InputLabel for="export_password" value="Current password" />
                <TextInput
                    id="export_password"
                    v-model="password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="error" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="processing">Download</PrimaryButton>
                <p v-if="done" class="text-sm text-gray-600" role="status">Downloaded.</p>
            </div>
        </form>
    </section>
</template>
