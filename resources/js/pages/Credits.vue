<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CreditsController from '@/actions/App/Http/Controllers/CreditsController';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

type Movement = {
    id: number;
    type: 'topup' | 'hold' | 'release' | 'charge';
    amount: string;
    prompt: string | null;
    created_at: string;
};

const props = defineProps<{
    balance: string;
    packs: string[];
    movements: Paginated<Movement>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: CreditsController.index() }],
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);
const negative = computed(() => props.balance.startsWith('-'));

const labels: Record<Movement['type'], string> = {
    topup: 'Credit added',
    hold: 'Held for an image',
    release: 'Hold released',
    charge: 'Charged for an image',
};

const when = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="Credits" />

    <div
        class="mx-auto grid w-full max-w-3xl gap-10 px-4 py-6 sm:px-6 lg:py-10"
    >
        <section aria-labelledby="balance-heading" class="grid gap-5">
            <div>
                <h1
                    id="balance-heading"
                    class="text-sm font-medium text-muted-foreground"
                >
                    Your balance
                </h1>
                <p
                    :class="[
                        'mt-1 text-4xl font-semibold tracking-tight tabular-nums',
                        negative ? 'text-destructive' : '',
                    ]"
                >
                    ${{ balance }}
                </p>
                <p v-if="negative" class="mt-2 text-sm text-destructive">
                    An image cost more than its hold because prices changed. Add
                    credit to keep creating.
                </p>
            </div>

            <div class="grid gap-3">
                <p class="text-sm">
                    Add credit. This is a demo: no payment is taken.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Form
                        v-for="pack in packs"
                        :key="pack"
                        v-bind="CreditsController.store.form()"
                        :options="{ preserveScroll: true }"
                        #default="{ processing }"
                    >
                        <input type="hidden" name="pack" :value="pack" />
                        <Button
                            type="submit"
                            variant="outline"
                            size="lg"
                            :disabled="processing"
                            class="min-w-28 tabular-nums active:scale-[0.97] motion-reduce:active:scale-100"
                        >
                            Add ${{ pack }}
                        </Button>
                    </Form>
                </div>
                <p v-if="status" class="text-sm text-success" role="status">
                    {{ status }}
                </p>
                <p
                    v-if="page.props.errors.pack"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ page.props.errors.pack }}
                </p>
            </div>
        </section>

        <section aria-labelledby="movements-heading" class="grid gap-2">
            <h2 id="movements-heading" class="text-lg font-semibold">
                Balance history
            </h2>
            <p class="text-sm text-muted-foreground">
                Sending an image holds its maximum price. When it finishes, the
                hold is released and the real price is charged.
            </p>

            <ul v-if="movements.data.length" class="divide-y border-y">
                <li
                    v-for="movement in movements.data"
                    :key="movement.id"
                    class="flex items-baseline justify-between gap-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium">
                            {{ labels[movement.type] }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            <template v-if="movement.prompt"
                                >{{ movement.prompt }},
                            </template>
                            {{ when(movement.created_at) }}
                        </p>
                    </div>
                    <p
                        :class="[
                            'shrink-0 text-sm tabular-nums',
                            movement.amount.startsWith('-')
                                ? 'text-foreground'
                                : 'text-success',
                        ]"
                    >
                        {{ movement.amount.startsWith('-') ? '-' : '+' }}${{
                            movement.amount.replace('-', '')
                        }}
                    </p>
                </li>
            </ul>
            <p v-else class="border-y py-10 text-center text-muted-foreground">
                No movements yet. Add credit above to start creating images.
            </p>

            <nav
                v-if="movements.meta.last_page > 1"
                class="flex items-center justify-between pt-2 text-sm"
                aria-label="Pages"
            >
                <Link
                    v-if="movements.links.prev"
                    :href="movements.links.prev"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                    >Newer</Link
                >
                <span v-else />
                <span class="text-muted-foreground tabular-nums">
                    Page {{ movements.meta.current_page }} of
                    {{ movements.meta.last_page }}
                </span>
                <Link
                    v-if="movements.links.next"
                    :href="movements.links.next"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                    >Older</Link
                >
                <span v-else />
            </nav>
        </section>
    </div>
</template>
