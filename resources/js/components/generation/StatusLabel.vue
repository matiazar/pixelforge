<script setup lang="ts">
import { computed } from 'vue';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

const labels: Record<GenerationStatus, string> = {
    pending: 'Waiting for a slot',
    queued: 'Queued',
    processing: 'Generating',
    succeeded: 'Done',
    failed: 'Failed',
};

const tone = computed(() => {
    switch (props.status) {
        case 'succeeded':
            return 'text-success';
        case 'failed':
            return 'text-destructive';
        case 'processing':
            return 'text-primary';
        default:
            return 'text-waiting';
    }
});

const live = computed(
    () => props.status === 'processing' || props.status === 'queued',
);
</script>

<template>
    <Transition
        mode="out-in"
        enter-active-class="transition-opacity duration-200 motion-reduce:transition-none"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150 motion-reduce:transition-none"
        leave-to-class="opacity-0"
    >
        <span
            :key="status"
            :class="[
                'inline-flex items-center gap-1.5 text-sm font-medium',
                tone,
            ]"
        >
            <span class="relative flex size-2" aria-hidden="true">
                <span
                    v-if="live"
                    class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-40 motion-reduce:hidden"
                />
                <span
                    class="relative inline-flex size-2 rounded-full bg-current"
                />
            </span>
            {{ labels[status] }}
        </span>
    </Transition>
</template>
