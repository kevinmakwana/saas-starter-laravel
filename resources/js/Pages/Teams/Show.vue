<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import type { PageProps, RoleName } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Member { id: number; name: string; email: string; role: RoleName }
interface Invitation { id: number; email: string; role: RoleName; expires_at: string; expired: boolean }

defineProps<{
    team: { id: number; name: string; owner_id: number };
    members: Member[];
    invitations: Invitation[];
    can: { invite: boolean; updateRoles: boolean; transfer: boolean; update: boolean; delete: boolean };
}>();

const me = usePage<PageProps>().props.auth.user;
const invite = useForm<{ email: string; role: RoleName }>({ email: '', role: 'member' });

const confirmingDeletion = ref(false);
const deletion = useForm({ password: '', name: '' });
// Not a form field: the server reports a billing failure under the `team` key.
const teamError = computed(() => (deletion.errors as Record<string, string | undefined>).team);
const closeDeletion = () => {
    confirmingDeletion.value = false;
    deletion.reset();
    deletion.clearErrors();
};
const deleteTeam = () => deletion.delete(route('teams.destroy'), { preserveScroll: true, onFinish: () => deletion.reset('password') });

const sendInvite = () => invite.post(route('teams.invitations.store'), { preserveScroll: true, onSuccess: () => invite.reset('email') });
const changeRole = (member: Member, role: string) => router.patch(route('teams.members.update', member.id), { role }, { preserveScroll: true });
const remove = (member: Member) => confirm(`Remove ${member.name}?`) && router.delete(route('teams.members.destroy', member.id), { preserveScroll: true });
const resend = (invitation: Invitation) => router.post(route('teams.invitations.resend', invitation.id), {}, { preserveScroll: true });
const cancel = (invitation: Invitation) => router.delete(route('teams.invitations.destroy', invitation.id), { preserveScroll: true });
const transfer = (member: Member) => confirm(`Make ${member.name} the owner? You will become an admin.`) && router.post(route('teams.ownership'), { user_id: member.id });
</script>

<template>
    <Head title="Team" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ team.name }}</h2>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 py-12 sm:px-6 lg:px-8">
            <section class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">Members</h3>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ member.name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ member.email }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                v-if="can.updateRoles && member.role !== 'owner'"
                                :value="member.role"
                                class="rounded-md border-gray-300 dark:border-gray-600 py-1 text-sm"
                                @change="changeRole(member, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="admin">Admin</option>
                                <option value="member">Member</option>
                            </select>
                            <span v-else class="rounded bg-gray-100 dark:bg-gray-900 px-2 py-1 text-xs font-medium capitalize text-gray-700 dark:text-gray-300">{{ member.role }}</span>
                            <SecondaryButton v-if="can.transfer && member.id !== me.id" @click="transfer(member)">Make owner</SecondaryButton>
                            <DangerButton v-if="member.role !== 'owner' && (member.id === me.id || can.invite)" @click="remove(member)">
                                {{ member.id === me.id ? 'Leave' : 'Remove' }}
                            </DangerButton>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="can.invite" class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">Invite someone</h3>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="sendInvite">
                    <div class="grow">
                        <InputLabel for="invite-email" value="Email" />
                        <TextInput id="invite-email" v-model="invite.email" type="email" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="invite.errors.email" />
                    </div>
                    <div>
                        <InputLabel for="invite-role" value="Role" />
                        <select id="invite-role" v-model="invite.role" class="mt-1 rounded-md border-gray-300 dark:border-gray-600 text-sm">
                            <option value="member">Member</option>
                            <option v-if="can.updateRoles" value="admin">Admin</option>
                        </select>
                        <InputError class="mt-2" :message="invite.errors.role" />
                    </div>
                    <PrimaryButton :disabled="invite.processing">Send invite</PrimaryButton>
                </form>

                <ul v-if="invitations.length" class="mt-6 divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="invitation in invitations" :key="invitation.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span>{{ invitation.email }} <span class="text-gray-500 dark:text-gray-400">· {{ invitation.role }} · {{ invitation.expired ? 'expired' : 'pending' }}</span></span>
                        <span class="flex gap-2">
                            <SecondaryButton @click="resend(invitation)">Resend</SecondaryButton>
                            <SecondaryButton @click="cancel(invitation)">Cancel</SecondaryButton>
                        </span>
                    </li>
                </ul>
            </section>

            <section v-if="can.delete" class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete team</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Deletes {{ team.name }} with its projects, tasks and activity history, and ends its subscription. Members are told by email. This cannot be undone.
                </p>
                <DangerButton class="mt-4" @click="confirmingDeletion = true">Delete team</DangerButton>

                <form v-if="confirmingDeletion" class="mt-4 space-y-4 border-t border-gray-100 dark:border-gray-700 pt-4" @submit.prevent="deleteTeam">
                    <div>
                        <InputLabel for="delete-name" :value="`Type “${team.name}” to confirm`" />
                        <TextInput id="delete-name" v-model="deletion.name" type="text" class="mt-1 block w-full" required autocomplete="off" />
                        <InputError class="mt-2" :message="deletion.errors.name" />
                    </div>
                    <div>
                        <InputLabel for="delete-password" value="Your password" />
                        <TextInput id="delete-password" v-model="deletion.password" type="password" class="mt-1 block w-full" required autocomplete="current-password" />
                        <InputError class="mt-2" :message="deletion.errors.password" />
                    </div>
                    <InputError :message="teamError" />
                    <div class="flex gap-3">
                        <DangerButton :disabled="deletion.processing">Delete team permanently</DangerButton>
                        <SecondaryButton type="button" @click="closeDeletion">Cancel</SecondaryButton>
                    </div>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
