<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, router } from '@inertiajs/vue3';

interface PlanCard {
    key: 'free' | 'pro' | 'business';
    name: string;
    price: number;
    limits: { projects: number | null; members: number | null };
    features: string[];
}

const props = defineProps<{
    current: PlanCard['key'];
    cancelsAt: string | null;
    canManage: boolean;
    usage: { projects: number; members: number };
    features: Record<string, boolean>;
    plans: PlanCard[];
}>();

const limit = (value: number | null) => (value === null ? 'Unlimited' : value);
const label = (feature: string) => feature.replace(/_/g, ' ');

function cancel() {
    if (window.confirm('Cancel your subscription?')) router.post(route('billing.cancel'));
}

function choose(plan: PlanCard) {
    if (plan.key === 'free') return;
    // Free teams subscribe; paid teams swap plans.
    const routeName = props.current === 'free' ? 'billing.subscribe' : 'billing.change';
    router.post(route(routeName), { plan: plan.key });
}
</script>

<template>
    <Head title="Billing" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Billing</h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 py-12 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600">
                Using {{ usage.projects }} projects and {{ usage.members }} members (including pending invitations).
                <span v-if="cancelsAt" class="font-medium text-amber-700">
                    Cancelled: your plan ends on {{ new Date(cancelsAt).toLocaleDateString() }}.
                </span>
            </p>

            <div class="grid gap-4 md:grid-cols-3">
                <section
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex flex-col bg-white p-6 shadow sm:rounded-lg"
                    :class="{ 'ring-2 ring-indigo-500': plan.key === current }"
                >
                    <h3 class="text-lg font-semibold text-gray-900">{{ plan.name }}</h3>
                    <p class="mt-1 text-3xl font-bold text-gray-900">${{ plan.price }}<span class="text-sm font-normal text-gray-500">/mo</span></p>
                    <ul class="mt-4 grow space-y-1 text-sm text-gray-600">
                        <li>{{ limit(plan.limits.projects) }} projects</li>
                        <li>{{ limit(plan.limits.members) }} members</li>
                        <li v-for="feature in plan.features" :key="feature" class="capitalize">{{ label(feature) }}</li>
                    </ul>
                    <div class="mt-6">
                        <span v-if="plan.key === current" class="text-sm font-medium text-indigo-600">Current plan</span>
                        <PrimaryButton v-else-if="canManage && plan.key !== 'free'" @click="choose(plan)">
                            {{ current === 'free' ? 'Subscribe' : 'Switch to ' + plan.name }}
                        </PrimaryButton>
                    </div>
                </section>
            </div>

            <div v-if="canManage && current !== 'free'" class="flex gap-3">
                <SecondaryButton v-if="cancelsAt" @click="router.post(route('billing.resume'))">Resume subscription</SecondaryButton>
                <DangerButton v-else @click="cancel">
                    Cancel subscription
                </DangerButton>
            </div>
            <p v-if="!canManage" class="text-sm text-gray-500">Only the team owner can change the plan.</p>
        </div>
    </AuthenticatedLayout>
</template>
