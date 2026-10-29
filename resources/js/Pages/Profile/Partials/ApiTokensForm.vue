<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import type { ApiToken } from '@/types';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    tokens: ApiToken[];
    teams: { id: number; name: string }[];
    // Sent once, by the request that made it.
    newToken?: string | null;
}>();

const form = useForm({ name: '', team_id: props.teams[0]?.id ?? null, access: 'read', expires: '90' });

// Asks for the password first (the server redirects to the confirm-password page), then back here.
const create = () => form.post(route('profile.api-tokens.store'), { preserveScroll: true, onSuccess: () => form.reset('name') });
const revoke = (token: ApiToken) =>
    window.confirm(`Revoke “${token.name}”? Anything using it stops working at once.`) &&
    router.delete(route('profile.api-tokens.destroy', token.id), { preserveScroll: true });

const when = (iso: string | null, none: string) => (iso ? new Date(iso).toLocaleDateString() : none);
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">API tokens</h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Let a script or another tool work with the projects and tasks of one of your teams. A token has your role in that team and is
                shown only once.
            </p>
        </header>

        <div v-if="newToken" class="mt-6 rounded-md bg-amber-50 dark:bg-amber-900/30 p-4 text-sm text-amber-900 dark:text-amber-200" data-testid="new-token">
            <p class="font-medium">Copy your new token now.</p>
            <p class="mt-1">It will not be shown again.</p>
            <code class="mt-3 block select-all break-all rounded bg-white/60 dark:bg-black/30 p-2 font-mono">{{ newToken }}</code>
        </div>

        <ul v-if="tokens.length" class="mt-6 divide-y divide-gray-200 dark:divide-gray-700 text-sm" data-testid="api-tokens">
            <li v-for="token in tokens" :key="token.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <p class="break-words font-medium text-gray-900 dark:text-gray-100">{{ token.name }}</p>
                    <p class="text-gray-600 dark:text-gray-400">
                        {{ token.team }} · {{ token.access === 'write' ? 'Read and write' : 'Read only' }} · Last used {{ when(token.last_used_at, 'never') }} · Expires
                        {{ when(token.expires_at, 'never') }}
                    </p>
                </div>
                <button type="button" class="text-red-600 dark:text-red-400 hover:underline" :aria-label="`Revoke ${token.name}`" @click="revoke(token)">Revoke</button>
            </li>
        </ul>

        <form class="mt-6 space-y-4" @submit.prevent="create">
            <div>
                <InputLabel for="token_name" value="Name" />
                <TextInput id="token_name" v-model="form.name" type="text" class="mt-1 block w-full max-w-xs" placeholder="e.g. Deploy script" required />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="flex flex-wrap gap-4">
                <div>
                    <InputLabel for="token_team" value="Team" />
                    <select id="token_team" v-model="form.team_id" class="mt-1 block rounded-md border-gray-300 dark:border-gray-600 text-sm">
                        <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.team_id" />
                </div>
                <div>
                    <InputLabel for="token_access" value="Access" />
                    <select id="token_access" v-model="form.access" class="mt-1 block rounded-md border-gray-300 dark:border-gray-600 text-sm">
                        <option value="read">Read only</option>
                        <option value="write">Read and write</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.access" />
                </div>
                <div>
                    <InputLabel for="token_expires" value="Expires" />
                    <select id="token_expires" v-model="form.expires" class="mt-1 block rounded-md border-gray-300 dark:border-gray-600 text-sm">
                        <option value="30">in 30 days</option>
                        <option value="90">in 90 days</option>
                        <option value="365">in a year</option>
                        <option value="never">never</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.expires" />
                </div>
            </div>

            <PrimaryButton :disabled="form.processing">Create token</PrimaryButton>
        </form>
    </section>
</template>
