<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatMegabytes } from '@/lib/format';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Deferred, Head, router } from '@inertiajs/vue3';

interface PlanCard {
    key: 'free' | 'pro' | 'business';
    name: string;
    price: number;
    /** null when the plan cannot be billed yearly. */
    yearlyPrice: number | null;
    limits: { projects: number | null; members: number | null; storage_mb: number | null };
    features: string[];
}

interface InvoiceRow {
    id: string;
    number: string | null;
    date: string;
    total: string;
    status: string;
    hosted_url: string | null;
    pdf_url: string | null;
}

type Interval = 'monthly' | 'yearly';

const props = defineProps<{
    current: PlanCard['key'];
    interval: Interval;
    /** When the free trial ends; null when the team is not trialing. */
    trialEndsAt: string | null;
    /** Days of trial a new subscription gets; 0 when none is on offer. */
    trialDays: number;
    cancelsAt: string | null;
    canManage: boolean;
    portal: boolean;
    /** Loaded after the page: undefined until then, null when the provider could not be reached. */
    invoices?: InvoiceRow[] | null;
    usage: { projects: number; members: number; storage_mb: number };
    features: Record<string, boolean>;
    plans: PlanCard[];
}>();

const { t, te, locale } = useI18n();

// Start on what the team pays today; a first-time subscriber sees the toggle on monthly.
const selected = ref<Interval>(props.interval);
const canPickInterval = computed(() => props.canManage && props.plans.some((plan) => plan.yearlyPrice !== null));

function priceOf(plan: PlanCard) {
    return selected.value === 'yearly' && plan.yearlyPrice !== null ? plan.yearlyPrice : plan.price;
}

function perOf(plan: PlanCard) {
    return selected.value === 'yearly' && plan.yearlyPrice !== null ? t('billing.perYear') : t('billing.perMonth');
}

/** Whether choosing this card changes anything: another plan, or the same plan billed differently. */
function changesBilling(plan: PlanCard) {
    if (plan.key === 'free') return false;
    const interval: Interval = plan.yearlyPrice === null ? 'monthly' : selected.value;

    return plan.key !== props.current || interval !== props.interval;
}

const unlimited = () => t('common.unlimited');
const featureLabel = (feature: string) => (te(`billing.features.${feature}`) ? t(`billing.features.${feature}`) : feature.replace(/_/g, ' '));
const statusLabel = (status: string) => (te(`billing.invoices.statuses.${status}`) ? t(`billing.invoices.statuses.${status}`) : status);
const date = (iso: string) => new Date(iso).toLocaleDateString(locale.value);

function cancel() {
    if (window.confirm(t('billing.confirmCancel'))) router.post(route('billing.cancel'));
}

function choose(plan: PlanCard) {
    if (plan.key === 'free') return;
    // Free teams subscribe; paid teams swap plans.
    const routeName = props.current === 'free' ? 'billing.subscribe' : 'billing.change';
    router.post(route(routeName), { plan: plan.key, interval: plan.yearlyPrice === null ? 'monthly' : selected.value });
}
</script>

<template>
    <Head :title="t('billing.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ t('billing.title') }}</h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 py-12 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ t('billing.using', { projects: usage.projects, members: usage.members, storage: formatMegabytes(usage.storage_mb, unlimited()) }) }}
                <span v-if="cancelsAt" class="font-medium text-amber-700 dark:text-amber-300">
                    {{ t('billing.cancelled', { date: date(cancelsAt) }) }}
                </span>
            </p>

            <p v-if="trialEndsAt" class="rounded-md bg-indigo-50 dark:bg-indigo-900/30 p-3 text-sm text-indigo-800 dark:text-indigo-300">
                {{ t('billing.trial', { date: date(trialEndsAt) }) }}
            </p>

            <div v-if="canPickInterval" class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-1 text-sm" role="group" :aria-label="t('billing.intervalLabel')">
                <button
                    v-for="option in (['monthly', 'yearly'] as Interval[])"
                    :key="option"
                    type="button"
                    :aria-pressed="selected === option"
                    class="rounded px-3 py-1 font-medium capitalize"
                    :class="selected === option ? 'bg-indigo-600 text-white' : 'text-gray-600 dark:text-gray-400'"
                    @click="selected = option"
                >
                    {{ t(`billing.${option}`) }}<span v-if="option === 'yearly'" class="ml-1 text-xs font-normal">{{ t('billing.twoMonthsFree') }}</span>
                </button>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <section
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex flex-col bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg"
                    :class="{ 'ring-2 ring-indigo-500': plan.key === current }"
                >
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ plan.name }}</h3>
                    <p class="mt-1 text-3xl font-bold text-gray-900 dark:text-gray-100">${{ priceOf(plan) }}<span class="text-sm font-normal text-gray-500 dark:text-gray-400">{{ perOf(plan) }}</span></p>
                    <ul class="mt-4 grow space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <li>{{ plan.limits.projects === null ? t('billing.projectsUnlimited') : t('billing.projects', { count: plan.limits.projects }) }}</li>
                        <li>{{ plan.limits.members === null ? t('billing.membersUnlimited') : t('billing.members', { count: plan.limits.members }) }}</li>
                        <li>{{ plan.limits.storage_mb === null ? t('billing.storageUnlimited') : t('billing.storage', { size: formatMegabytes(plan.limits.storage_mb) }) }}</li>
                        <li v-for="feature in plan.features" :key="feature" class="capitalize">{{ featureLabel(feature) }}</li>
                    </ul>
                    <div class="mt-6">
                        <span v-if="plan.key === current" class="mr-3 text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ t('billing.currentPlan') }}</span>
                        <PrimaryButton v-if="canManage && changesBilling(plan)" @click="choose(plan)">
                            <template v-if="current === 'free'">{{ trialDays > 0 ? t('billing.startTrial', { days: trialDays }) : t('billing.subscribe') }}</template>
                            <template v-else-if="plan.key === current">{{ selected === 'yearly' ? t('billing.switchYearly') : t('billing.switchMonthly') }}</template>
                            <template v-else>{{ t('billing.switchTo', { plan: plan.name }) }}</template>
                        </PrimaryButton>
                    </div>
                </section>
            </div>

            <div v-if="canManage && current !== 'free'" class="flex gap-3">
                <SecondaryButton v-if="cancelsAt" @click="router.post(route('billing.resume'))">{{ t('billing.resume') }}</SecondaryButton>
                <DangerButton v-else @click="cancel">
                    {{ t('billing.cancel') }}
                </DangerButton>
            </div>
            <div v-if="portal" class="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-400">
                <!-- A plain link: it redirects to Stripe's Customer Portal, which is not an Inertia page. -->
                <a :href="route('billing.portal')" class="font-medium text-indigo-700 dark:text-indigo-400 underline">{{ t('billing.updatePayment') }}</a>
                <span>{{ t('billing.updatePaymentHint') }}</span>
            </div>

            <section v-if="canManage" class="bg-white dark:bg-gray-800 p-6 shadow sm:rounded-lg">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('billing.invoices.title') }}</h3>
                <Deferred data="invoices">
                    <template #fallback>
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ t('billing.invoices.loading') }}</p>
                    </template>
                    <p v-if="invoices === null" class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ t('billing.invoices.failed') }}</p>
                    <p v-else-if="!invoices?.length" class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ t('billing.invoices.none') }}</p>
                    <table v-else class="mt-3 w-full text-left text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 font-medium">{{ t('billing.invoices.number') }}</th>
                                <th class="py-2 font-medium">{{ t('billing.invoices.date') }}</th>
                                <th class="py-2 font-medium">{{ t('billing.invoices.amount') }}</th>
                                <th class="py-2 font-medium">{{ t('billing.invoices.status') }}</th>
                                <th class="py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="invoice in invoices" :key="invoice.id">
                                <td class="py-2">{{ invoice.number ?? invoice.id }}</td>
                                <td class="py-2">{{ date(invoice.date) }}</td>
                                <td class="py-2">{{ invoice.total }}</td>
                                <td class="py-2 capitalize">{{ statusLabel(invoice.status) }}</td>
                                <td class="space-x-3 py-2 text-right">
                                    <a v-if="invoice.hosted_url" :href="invoice.hosted_url" target="_blank" rel="noopener" class="text-indigo-700 dark:text-indigo-400 underline">{{ t('billing.invoices.view') }}</a>
                                    <a v-if="invoice.pdf_url" :href="invoice.pdf_url" class="text-indigo-700 dark:text-indigo-400 underline">{{ t('billing.invoices.pdf') }}</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </Deferred>
            </section>

            <p v-if="!canManage" class="text-sm text-gray-500 dark:text-gray-400">{{ t('billing.ownerOnly') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
