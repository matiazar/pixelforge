<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReconciliationController from '@/actions/App/Http/Controllers/Admin/ReconciliationController';
import DateRangeFilter from '@/components/admin/DateRangeFilter.vue';
import { Button } from '@/components/ui/button';

type Row = {
    date: string;
    our_generations: number;
    provider_generations: number;
    our_cost: string;
    provider_cost: string;
    difference: string;
    matches: boolean;
};

const props = defineProps<{
    filters: { from: string; to: string };
    rows: Row[];
    error: string | null;
    open: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Reconciliation',
                href: ReconciliationController.index(),
            },
        ],
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);
const mismatches = computed(() => props.rows.filter((r) => !r.matches).length);
</script>

<template>
    <Head title="Reconciliation" />

    <div class="mx-auto grid w-full max-w-5xl gap-8 px-4 py-6 sm:px-6 lg:py-10">
        <header class="grid gap-4">
            <h1 class="text-2xl font-semibold tracking-tight">
                Reconciliation
            </h1>
            <DateRangeFilter
                :action="ReconciliationController.index.form()"
                :filters="filters"
                hint="Our recorded Pixelforge cost against Pixelforge's own usage, per UTC day, for the current API key only. Amounts must match to the nano-dollar."
            />
        </header>

        <section
            aria-labelledby="pending-heading"
            class="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4"
        >
            <div>
                <h2 id="pending-heading" class="font-medium">
                    <span class="tabular-nums">{{ open }}</span>
                    {{ open === 1 ? 'image' : 'images' }} still open at
                    Pixelforge
                </h2>
                <p class="text-sm text-muted-foreground">
                    Open images are not in our cost yet. Sync asks Pixelforge
                    about them now instead of waiting for the next webhook or
                    poll.
                </p>
            </div>
            <Form
                v-bind="ReconciliationController.sync.form()"
                :options="{ preserveScroll: true }"
                #default="{ processing }"
            >
                <Button type="submit" :disabled="processing">
                    {{ processing ? 'Syncing...' : 'Sync pending' }}
                </Button>
            </Form>
            <p v-if="status" class="w-full text-sm text-success" role="status">
                {{ status }}
            </p>
        </section>

        <p
            v-if="error"
            class="rounded-lg border border-destructive/40 p-4 text-sm text-destructive"
            role="alert"
        >
            {{ error }}
        </p>

        <section v-else aria-labelledby="days-heading" class="grid gap-3">
            <h2 id="days-heading" class="text-lg font-semibold">
                By UTC day
                <span
                    :class="[
                        'ml-2 text-sm font-normal',
                        mismatches ? 'text-destructive' : 'text-success',
                    ]"
                >
                    {{
                        mismatches
                            ? `${mismatches} ${mismatches === 1 ? 'day differs' : 'days differ'}`
                            : 'All days match'
                    }}
                </span>
            </h2>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Day (UTC)
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Our images
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Pixelforge images
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Our cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Pixelforge cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Difference
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr
                            v-for="row in rows"
                            :key="row.date"
                            :class="row.matches ? '' : 'bg-destructive/5'"
                        >
                            <th
                                scope="row"
                                class="px-3 py-2 text-left font-medium whitespace-nowrap"
                            >
                                {{ row.date }}
                            </th>
                            <td class="px-3 py-2 text-right">
                                {{ row.our_generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ row.provider_generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                ${{ row.our_cost }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                ${{ row.provider_cost }}
                            </td>
                            <td
                                :class="[
                                    'px-3 py-2 text-right',
                                    row.matches
                                        ? 'text-success'
                                        : 'font-medium text-destructive',
                                ]"
                            >
                                {{
                                    row.matches ? 'Match' : `$${row.difference}`
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
