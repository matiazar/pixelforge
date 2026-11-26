<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ImageOff, LoaderCircle, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { retry } from '@/actions/App/Http/Controllers/GenerationController';
import StatusLabel from '@/components/generation/StatusLabel.vue';
import { Button } from '@/components/ui/button';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const details = computed(() => {
    const g = props.generation;
    const parts = [g.size, g.quality ? `${g.quality} quality` : null];

    if (g.reference_count > 0) {
        parts.push(
            `${g.reference_count} reference${g.reference_count > 1 ? 's' : ''}`,
        );
    }

    return parts.filter(Boolean).join(', ');
});

const createdAt = computed(() =>
    props.generation.created_at
        ? new Date(props.generation.created_at).toLocaleString()
        : '',
);
</script>

<template>
    <li
        class="grid grid-cols-[4.5rem_1fr] gap-4 py-5 sm:grid-cols-[6rem_1fr_auto] sm:gap-6"
    >
        <div
            class="flex aspect-square items-center justify-center overflow-hidden rounded-md bg-muted text-muted-foreground"
        >
            <a
                v-if="generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener noreferrer"
                class="block size-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="size-full object-cover"
                    loading="lazy"
                />
            </a>
            <span
                v-else-if="generation.image_expired"
                class="flex flex-col items-center gap-1 px-1 text-center text-[11px] leading-tight"
            >
                <ImageOff class="size-4" aria-hidden="true" />
                Image expired
            </span>
            <LoaderCircle
                v-else-if="generation.status !== 'failed'"
                class="size-5 animate-spin motion-reduce:animate-none"
                aria-hidden="true"
            />
        </div>

        <div class="min-w-0">
            <StatusLabel :status="generation.status" />
            <p class="mt-1 line-clamp-2 text-[15px] leading-snug">
                {{ generation.prompt }}
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ details
                }}<template v-if="createdAt">, {{ createdAt }}</template>
                <template v-if="generation.is_retry">, retry</template>
            </p>
            <p
                v-if="generation.error_message"
                class="mt-2 text-sm text-destructive"
            >
                {{ generation.error_message }}
            </p>
        </div>

        <div
            class="col-span-2 flex items-end justify-between gap-4 border-t border-dashed pt-3 sm:col-span-1 sm:flex-col sm:items-end sm:border-t-0 sm:border-l sm:pt-0 sm:pl-6"
        >
            <dl class="text-sm tabular-nums sm:text-right">
                <template v-if="generation.price !== null">
                    <dt class="text-muted-foreground">You paid</dt>
                    <dd class="text-base font-semibold">
                        ${{ generation.price }}
                    </dd>
                </template>
                <template v-else>
                    <dt class="text-muted-foreground">Estimate</dt>
                    <dd>
                        ${{ generation.estimate.min }} to ${{
                            generation.estimate.max
                        }}
                    </dd>
                </template>
            </dl>

            <Form
                v-if="generation.can_retry"
                v-bind="retry.form(generation.id)"
                :options="{ preserveScroll: true }"
                #default="{ processing, errors }"
                class="grid justify-items-end gap-1"
            >
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    :disabled="processing"
                    class="active:scale-[0.97] motion-reduce:active:scale-100"
                >
                    <RotateCcw aria-hidden="true" />
                    Try again
                </Button>
                <p
                    v-if="errors.balance || errors.daily_limit"
                    class="max-w-56 text-right text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.balance ?? errors.daily_limit }}
                </p>
            </Form>
        </div>
    </li>
</template>
