<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PageProps } from '@/types';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage<PageProps>();
const teams = computed(() => page.props.auth.teams ?? []);
const current = computed(() => page.props.auth.team?.id ?? '');

function switchTeam(event: Event) {
    const value = (event.target as HTMLSelectElement).value;

    if (value === 'new') {
        router.visit(route('teams.create'));
        return;
    }

    router.put(route('teams.switch'), { team_id: Number(value) });
}
</script>

<template>
    <select
        v-if="teams.length"
        :value="current"
        :aria-label="t('teamSwitcher.label')"
        class="rounded-md border-gray-300 dark:border-gray-600 py-1.5 text-sm text-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        @change="switchTeam"
    >
        <option v-for="team in teams" :key="team.id" :value="team.id">
            {{ team.name }}
        </option>
        <option value="new">{{ t('teamSwitcher.new') }}</option>
    </select>
</template>
