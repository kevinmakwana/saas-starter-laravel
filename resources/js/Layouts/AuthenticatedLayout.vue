<script setup lang="ts">
import { computed, ref } from 'vue';
import type { PageProps } from '@/types';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import TeamSwitcher from '@/Components/TeamSwitcher.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue';

const { t, locale } = useI18n();
const showingNavigationDropdown = ref(false);
const page = usePage<PageProps>();
const auth = computed(() => page.props.auth);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <nav
                class="border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    {{ t('nav.dashboard') }}
                                </NavLink>
                                <NavLink
                                    :href="route('projects.index')"
                                    :active="route().current('projects.*')"
                                >
                                    {{ t('nav.projects') }}
                                </NavLink>
                                <NavLink
                                    v-if="auth.features?.audit_log && ['owner', 'admin'].includes(auth.team?.role ?? '')"
                                    :href="route('audit-log.index')"
                                    :active="route().current('audit-log.*')"
                                >
                                    {{ t('nav.auditLog') }}
                                </NavLink>
                                <NavLink
                                    :href="route('teams.show')"
                                    :active="route().current('teams.*')"
                                >
                                    {{ t('nav.team') }}
                                </NavLink>
                                <NavLink
                                    :href="route('billing.show')"
                                    :active="route().current('billing.*')"
                                >
                                    {{ t('nav.billing') }}
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <NotificationBell class="me-1" />
                            <LocaleSwitcher class="me-3" />
                            <ThemeToggle class="me-3" />
                            <TeamSwitcher />
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white dark:bg-gray-800 px-3 py-2 text-sm font-medium leading-4 text-gray-500 dark:text-gray-400 transition duration-150 ease-in-out hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            {{ t('nav.profile') }}
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            {{ t('nav.logOut') }}
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center gap-1 sm:hidden">
                            <NotificationBell />
                            <ThemeToggle />
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-500 dark:hover:text-gray-300 focus:bg-gray-100 dark:focus:bg-gray-700 focus:text-gray-500 dark:focus:text-gray-300 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            {{ t('nav.dashboard') }}
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('projects.index')" :active="route().current('projects.*')">
                            {{ t('nav.projects') }}
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="auth.features?.audit_log && ['owner', 'admin'].includes(auth.team?.role ?? '')"
                            :href="route('audit-log.index')"
                            :active="route().current('audit-log.*')"
                        >
                            {{ t('nav.auditLog') }}
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('teams.show')" :active="route().current('teams.*')">
                            {{ t('nav.team') }}
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('billing.show')" :active="route().current('billing.*')">
                            {{ t('nav.billing') }}
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 dark:border-gray-700 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800 dark:text-gray-200"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 px-4">
                            <LocaleSwitcher class="w-full" />
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                {{ t('nav.profile') }}
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                {{ t('nav.logOut') }}
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white dark:bg-gray-800 shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <div
                v-if="page.props.flash?.success"
                class="mx-auto mt-4 max-w-7xl rounded-md bg-green-50 dark:bg-green-900/30 px-4 py-3 text-sm text-green-800 dark:text-green-200 sm:px-6 lg:px-8"
                role="status"
            >
                {{ page.props.flash.success }}
            </div>

            <div
                v-if="auth.billing"
                class="mx-auto mt-4 flex max-w-7xl items-center justify-between rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-200 sm:px-6 lg:px-8"
                role="alert"
            >
                <span v-if="auth.billing.state === 'grace'">
                    {{ t('layout.billingGrace', { date: new Date(auth.billing.grace_ends_at ?? '').toLocaleDateString(locale) }) }}
                </span>
                <span v-else>{{ t('layout.billingOverdue') }}</span>
                <Link :href="route('billing.show')" class="font-semibold underline">{{ t('layout.reviewBilling') }}</Link>
            </div>

            <div
                v-if="auth.overLimit"
                class="mx-auto mt-4 flex max-w-7xl items-center justify-between gap-4 rounded-md bg-amber-50 dark:bg-amber-900/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-200 sm:px-6 lg:px-8"
                role="status"
            >
                <span>
                    {{
                        t('layout.overLimit', {
                            plan: auth.overLimit.plan,
                            items: auth.overLimit.items.map((item) => t('layout.overLimitItem', { used: item.used, limit: item.limit, label: t(`limits.${item.label}`) })).join(', '),
                        })
                    }}
                </span>
                <Link :href="route('billing.show')" class="shrink-0 font-semibold underline">{{ t('layout.seePlans') }}</Link>
            </div>

            <div
                v-if="page.props.flash?.error"
                class="mx-auto mt-4 max-w-7xl rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-200 sm:px-6 lg:px-8"
                role="alert"
            >
                {{ page.props.flash.error }}
            </div>

            <div
                v-if="page.props.flash?.upgrade"
                class="mx-auto mt-4 flex max-w-7xl items-center justify-between rounded-md bg-amber-50 dark:bg-amber-900/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-200 sm:px-6 lg:px-8"
                role="alert"
            >
                <span>{{ page.props.flash.upgrade }}</span>
                <Link :href="route('billing.show')" class="font-semibold underline">{{ t('layout.seePlans') }}</Link>
            </div>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
