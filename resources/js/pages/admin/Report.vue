<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import DateRangeFilter from '@/components/admin/DateRangeFilter.vue';

type Amounts = {
    generations: number;
    charged: string;
    cost: string;
    profit: string;
};

defineProps<{
    timezone: string;
    filters: { from: string; to: string };
    days: (Amounts & { date: string })[];
    users: (Amounts & { name: string; email: string })[];
    totals: Amounts;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: ReportController.index() }],
    },
});

const negative = (amount: string) => amount.startsWith('-');
</script>

<template>
    <Head title="Report" />

    <div class="mx-auto grid w-full max-w-5xl gap-8 px-4 py-6 sm:px-6 lg:py-10">
        <header class="grid gap-4">
            <h1 class="text-2xl font-semibold tracking-tight">Report</h1>
            <DateRangeFilter
                :action="ReportController.index.form()"
                :filters="filters"
                :hint="`Charged images grouped by the day they finished, in ${timezone}. Profit is what users paid minus the Pixelforge cost.`"
            />
        </header>

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Images</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ totals.generations }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Charged</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    ${{ totals.charged }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Provider cost</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    ${{ totals.cost }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd
                    :class="[
                        'mt-1 text-2xl font-semibold tabular-nums',
                        negative(totals.profit)
                            ? 'text-destructive'
                            : 'text-success',
                    ]"
                >
                    ${{ totals.profit }}
                </dd>
            </div>
        </dl>

        <section aria-labelledby="by-day" class="grid gap-3">
            <h2 id="by-day" class="text-lg font-semibold">By day</h2>
            <div v-if="days.length" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Day
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Images
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Charged
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr v-for="day in days" :key="day.date">
                            <th
                                scope="row"
                                class="px-3 py-2 text-left font-medium whitespace-nowrap"
                            >
                                {{ day.date }}
                            </th>
                            <td class="px-3 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                ${{ day.charged }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                ${{ day.cost }}
                            </td>
                            <td
                                :class="[
                                    'px-3 py-2 text-right',
                                    negative(day.profit)
                                        ? 'text-destructive'
                                        : '',
                                ]"
                            >
                                ${{ day.profit }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-else
                class="rounded-lg border py-10 text-center text-muted-foreground"
            >
                No charged images in this range.
            </p>
        </section>

        <section aria-labelledby="by-user" class="grid gap-3">
            <h2 id="by-user" class="text-lg font-semibold">By user</h2>
            <div v-if="users.length" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                User
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Images
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Charged
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr v-for="user in users" :key="user.email">
                            <th
                                scope="row"
                                class="px-3 py-2 text-left font-normal"
                            >
                                <span class="font-medium">{{ user.name }}</span>
                                <span class="block text-muted-foreground">{{
                                    user.email
                                }}</span>
                            </th>
                            <td class="px-3 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                ${{ user.charged }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                ${{ user.cost }}
                            </td>
                            <td
                                :class="[
                                    'px-3 py-2 text-right',
                                    negative(user.profit)
                                        ? 'text-destructive'
                                        : '',
                                ]"
                            >
                                ${{ user.profit }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-else
                class="rounded-lg border py-10 text-center text-muted-foreground"
            >
                No charged images in this range.
            </p>
        </section>
    </div>
</template>
