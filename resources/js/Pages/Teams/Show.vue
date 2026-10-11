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

interface Member { id: number; name: string; email: string; role: RoleName }
interface Invitation { id: number; email: string; role: RoleName; expires_at: string }

defineProps<{
    team: { id: number; name: string; owner_id: number };
    members: Member[];
    invitations: Invitation[];
    can: { invite: boolean; updateRoles: boolean; transfer: boolean; update: boolean };
}>();

const me = usePage<PageProps>().props.auth.user;
const invite = useForm<{ email: string; role: RoleName }>({ email: '', role: 'member' });

const sendInvite = () => invite.post(route('teams.invitations.store'), { preserveScroll: true, onSuccess: () => invite.reset('email') });
const changeRole = (member: Member, role: string) => router.patch(route('teams.members.update', member.id), { role }, { preserveScroll: true });
const remove = (member: Member) => confirm(`Remove ${member.name}?`) && router.delete(route('teams.members.destroy', member.id), { preserveScroll: true });
const cancel = (invitation: Invitation) => router.delete(route('teams.invitations.destroy', invitation.id), { preserveScroll: true });
const transfer = (member: Member) => confirm(`Make ${member.name} the owner? You will become an admin.`) && router.post(route('teams.ownership'), { user_id: member.id });
</script>

<template>
    <Head title="Team" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ team.name }}</h2>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 py-12 sm:px-6 lg:px-8">
            <section class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="mb-4 text-lg font-medium text-gray-900">Members</h3>
                <ul class="divide-y divide-gray-100">
                    <li v-for="member in members" :key="member.id" class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <p class="font-medium text-gray-900">{{ member.name }}</p>
                            <p class="text-sm text-gray-500">{{ member.email }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <select
                                v-if="can.updateRoles && member.role !== 'owner'"
                                :value="member.role"
                                class="rounded-md border-gray-300 py-1 text-sm"
                                @change="changeRole(member, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="admin">Admin</option>
                                <option value="member">Member</option>
                            </select>
                            <span v-else class="rounded bg-gray-100 px-2 py-1 text-xs font-medium capitalize text-gray-700">{{ member.role }}</span>
                            <SecondaryButton v-if="can.transfer && member.id !== me.id" @click="transfer(member)">Make owner</SecondaryButton>
                            <DangerButton v-if="member.role !== 'owner' && (member.id === me.id || can.invite)" @click="remove(member)">
                                {{ member.id === me.id ? 'Leave' : 'Remove' }}
                            </DangerButton>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="can.invite" class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="mb-4 text-lg font-medium text-gray-900">Invite someone</h3>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="sendInvite">
                    <div class="grow">
                        <InputLabel for="invite-email" value="Email" />
                        <TextInput id="invite-email" v-model="invite.email" type="email" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="invite.errors.email" />
                    </div>
                    <div>
                        <InputLabel for="invite-role" value="Role" />
                        <select id="invite-role" v-model="invite.role" class="mt-1 rounded-md border-gray-300 text-sm">
                            <option value="member">Member</option>
                            <option v-if="can.updateRoles" value="admin">Admin</option>
                        </select>
                        <InputError class="mt-2" :message="invite.errors.role" />
                    </div>
                    <PrimaryButton :disabled="invite.processing">Send invite</PrimaryButton>
                </form>

                <ul v-if="invitations.length" class="mt-6 divide-y divide-gray-100">
                    <li v-for="invitation in invitations" :key="invitation.id" class="flex items-center justify-between py-2 text-sm">
                        <span>{{ invitation.email }} <span class="text-gray-500">· {{ invitation.role }} · pending</span></span>
                        <SecondaryButton @click="cancel(invitation)">Cancel</SecondaryButton>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
