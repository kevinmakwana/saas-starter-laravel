<script setup lang="ts">
import { notificationKey, timeAgo, type NotificationParams } from '@/lib/notificationText';
import type { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const POLL_MS = 60_000;

const { t, te, locale } = useI18n();
const page = usePage<PageProps>();
const notifications = computed(() => page.props.notifications);
const unread = computed(() => notifications.value?.unread ?? 0);

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const label = computed(() => (unread.value > 0 ? t('notifications.labelUnread', { count: unread.value }) : t('notifications.title')));
const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

// The server sends a kind and its values; the sentence is built here, in the language of the page.
const sentence = (kind: string, params: NotificationParams): string => {
    const key = notificationKey(kind);
    if (!te(key)) {
        return t('notifications.fallback');
    }

    const date = params.date ? new Date(`${params.date}T00:00:00`).toLocaleDateString(locale.value) : '';

    return t(key, { ...params, date });
};

const openOne = (id: string) => {
    open.value = false;
    router.post(route('notifications.open', id));
};

const readAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });

// Quietly refresh just the bell while the tab is in view, so new notifications show up without a reload.
const refresh = () => {
    if (document.visibilityState === 'visible') {
        router.reload({ only: ['notifications'] });
    }
};

const closeOnOutsideClick = (event: MouseEvent) => {
    if (open.value && root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
};

const closeOnEscape = (event: KeyboardEvent) => {
    if (open.value && event.key === 'Escape') {
        open.value = false;
    }
};

let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', refresh);
    document.addEventListener('click', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
});

onUnmounted(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', refresh);
    document.removeEventListener('click', closeOnOutsideClick);
    document.removeEventListener('keydown', closeOnEscape);
});
</script>

<template>
    <div v-if="notifications" ref="root" class="relative">
        <button
            type="button"
            :aria-label="label"
            :aria-expanded="open"
            class="relative inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-300"
            @click="open = !open"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            <span
                v-if="unread > 0"
                data-testid="notification-badge"
                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white"
                aria-hidden="true"
            >{{ badge }}</span>
        </button>

        <div
            v-if="open"
            data-testid="notification-panel"
            class="fixed inset-x-4 top-16 z-50 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-80 dark:bg-gray-800 dark:ring-white/10"
        >
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('notifications.title') }}</h3>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="text-xs text-indigo-700 underline hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                    @click="readAll"
                >
                    {{ t('notifications.markAllRead') }}
                </button>
            </div>

            <p v-if="notifications.items.length === 0" class="px-4 py-6 text-center text-sm text-gray-600 dark:text-gray-400">
                {{ t('notifications.caughtUp') }}
            </p>

            <ul v-else class="max-h-96 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                <li v-for="item in notifications.items" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full items-start gap-3 px-4 py-3 text-left text-sm hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                        :data-unread="!item.read"
                        @click="openOne(item.id)"
                    >
                        <span
                            class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                            :class="item.read ? 'bg-transparent' : 'bg-indigo-600 dark:bg-indigo-400'"
                            aria-hidden="true"
                        />
                        <span class="min-w-0">
                            <span class="block text-gray-900 dark:text-gray-100" :class="{ 'font-medium': !item.read }">
                                <span v-if="!item.read" class="sr-only">{{ t('notifications.unread') }} </span>{{ sentence(item.kind, item.params) }}
                            </span>
                            <span class="mt-0.5 block text-xs text-gray-600 dark:text-gray-400">{{ timeAgo(item.created_at, locale) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
