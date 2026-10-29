<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { formatBytes } from '@/lib/format';
import type { AttachmentRow } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    projectId: number;
    taskId: number;
    attachments: AttachmentRow[];
    /** Largest single file, in kilobytes, and the file types accepted, for a friendly check before uploading. */
    maxFileKb: number;
    extensions: string[];
}>();

const { t } = useI18n();

const input = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null }>({ file: null });
const accept = computed(() => props.extensions.map((extension) => `.${extension}`).join(','));
const tooBig = ref(false);

const pick = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    tooBig.value = false;
    if (!file) {
        return;
    }

    // The server checks everything again; this only saves a round trip for an obvious mistake.
    if (file.size > props.maxFileKb * 1024) {
        tooBig.value = true;
        (event.target as HTMLInputElement).value = '';
        return;
    }

    form.file = file;
    form.post(route('attachments.store', [props.projectId, props.taskId]), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            form.reset();
            if (input.value) {
                input.value.value = '';
            }
        },
    });
};

const remove = (file: AttachmentRow) => {
    if (window.confirm(t('attachments.confirmRemove', { name: file.name }))) {
        router.delete(route('attachments.destroy', file.id), { preserveScroll: true });
    }
};
</script>

<template>
    <div class="border-t border-gray-100 bg-gray-50 px-4 py-3 text-sm dark:border-gray-700 dark:bg-gray-900" :data-testid="`files-${taskId}`">
        <ul v-if="attachments.length" class="divide-y divide-gray-100 dark:divide-gray-700">
            <li v-for="file in attachments" :key="file.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
                <div class="min-w-0">
                    <a :href="route('attachments.download', file.id)" class="break-all text-indigo-700 underline dark:text-indigo-400">{{ file.name }}</a>
                    <span class="ms-2 text-xs text-gray-600 dark:text-gray-400">{{ formatBytes(file.size) }}<template v-if="file.uploader"> · {{ file.uploader }}</template></span>
                </div>
                <button type="button" class="text-red-600 hover:underline dark:text-red-400" :aria-label="t('attachments.removeAria', { name: file.name })" @click="remove(file)">{{ t('common.remove') }}</button>
            </li>
        </ul>
        <p v-else class="text-gray-600 dark:text-gray-400">{{ t('attachments.empty') }}</p>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <label class="cursor-pointer rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 focus-within:ring-2 focus-within:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" :class="{ 'opacity-50': form.processing }">
                {{ form.processing ? t('attachments.uploading') : t('attachments.attach') }}
                <input ref="input" type="file" class="sr-only" :accept="accept" :disabled="form.processing" :aria-label="t('attachments.attachAria', { id: taskId })" @change="pick" />
            </label>
            <span v-if="form.progress" class="text-xs text-gray-600 dark:text-gray-400" role="status">{{ form.progress.percentage }}%</span>
        </div>
        <InputError class="mt-2" :message="tooBig ? t('attachments.tooBig', { mb: Math.round(maxFileKb / 1024) }) : form.errors.file" />
    </div>
</template>
