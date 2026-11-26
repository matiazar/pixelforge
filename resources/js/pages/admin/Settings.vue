<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SettingsController from '@/actions/App/Http/Controllers/Admin/SettingsController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    settings: {
        timezone: string;
        daily_limit: number;
        registration_open: boolean;
    };
    timezones: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'App settings', href: SettingsController.edit() },
        ],
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
</script>

<template>
    <Head title="App settings" />

    <div class="mx-auto grid w-full max-w-xl gap-6 px-4 py-6 sm:px-6 lg:py-10">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">App settings</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Apply to every user. The 25% margin over the Pixelforge cost is
                fixed and not editable.
            </p>
        </header>

        <Form
            v-bind="SettingsController.update.form()"
            :options="{ preserveScroll: true }"
            class="grid gap-6"
            #default="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="timezone">Report timezone</Label>
                <select
                    id="timezone"
                    name="timezone"
                    :class="selectClass"
                    required
                >
                    <option
                        v-for="tz in timezones"
                        :key="tz"
                        :value="tz"
                        :selected="tz === settings.timezone"
                    >
                        {{ tz }}
                    </option>
                </select>
                <p class="text-sm text-muted-foreground">
                    Days in the report. Reconciliation always uses UTC days.
                </p>
                <InputError :message="errors.timezone" />
            </div>

            <div class="grid gap-2">
                <Label for="daily_limit">Daily limit per user</Label>
                <Input
                    id="daily_limit"
                    name="daily_limit"
                    type="number"
                    min="1"
                    max="1000"
                    step="1"
                    inputmode="numeric"
                    :default-value="settings.daily_limit"
                    class="w-32 tabular-nums"
                    required
                />
                <p class="text-sm text-muted-foreground">
                    Images a user can send per UTC day.
                </p>
                <InputError :message="errors.daily_limit" />
            </div>

            <div class="grid gap-2">
                <Label for="registration_open">Registration</Label>
                <select
                    id="registration_open"
                    name="registration_open"
                    :class="selectClass"
                >
                    <option value="1" :selected="settings.registration_open">
                        Open: anyone can create an account
                    </option>
                    <option value="0" :selected="!settings.registration_open">
                        Closed: only existing accounts can log in
                    </option>
                </select>
                <InputError :message="errors.registration_open" />
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="processing">Save</Button>
                <p v-if="status" class="text-sm text-success" role="status">
                    {{ status }}
                </p>
            </div>
        </Form>
    </div>
</template>
