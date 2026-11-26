<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationForm from '@/components/generation/GenerationForm.vue';
import GenerationRow from '@/components/generation/GenerationRow.vue';
import { dashboard } from '@/routes';
import type { Generation, GenerationOptions, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    options: GenerationOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Create', href: dashboard() }],
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);
const queued = computed(() => status.value === 'Your request is in the queue');

// Refresh from our own database while anything on this page is still open.
const hasOpen = computed(() =>
    props.generations.data.some(
        (g) => g.status !== 'succeeded' && g.status !== 'failed',
    ),
);
const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'flash'] },
    { autoStart: false },
);
watch(hasOpen, (open) => (open ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="Create" />

    <div
        class="mx-auto grid w-full max-w-5xl gap-10 px-4 py-6 sm:px-6 lg:py-10"
    >
        <section aria-labelledby="create-heading" class="grid gap-5">
            <h1
                id="create-heading"
                class="text-2xl font-semibold tracking-tight"
            >
                Create an image
            </h1>
            <GenerationForm :options="options" />
        </section>

        <section aria-labelledby="history-heading" class="grid gap-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="history-heading" class="text-lg font-semibold">
                    Your images
                </h2>
                <p
                    v-if="status"
                    :class="[
                        'text-sm',
                        queued ? 'text-waiting' : 'text-muted-foreground',
                    ]"
                    role="status"
                >
                    {{ status }}
                </p>
            </div>

            <ul v-if="generations.data.length" class="divide-y border-y">
                <GenerationRow
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </ul>
            <p v-else class="border-y py-10 text-center text-muted-foreground">
                Nothing here yet. Describe an image above and it will show up
                here while it is made.
            </p>

            <nav
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between pt-2 text-sm"
                aria-label="Pages"
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                >
                    Newer
                </Link>
                <span v-else />
                <span class="text-muted-foreground tabular-nums">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                >
                    Older
                </Link>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
