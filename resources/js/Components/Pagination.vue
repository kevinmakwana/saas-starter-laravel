<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{ page: Paginated<unknown> }>();
</script>

<template>
    <nav v-if="page.last_page > 1" class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm" aria-label="Pagination">
        <p class="text-gray-500">Showing {{ page.from }}–{{ page.to }} of {{ page.total }}</p>
        <div class="flex flex-wrap gap-1">
            <template v-for="link in page.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded border px-3 py-1"
                    :class="link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
                    v-html="link.label"
                />
                <span v-else class="rounded border border-gray-200 px-3 py-1 text-gray-400" v-html="link.label" />
            </template>
        </div>
    </nav>
</template>
