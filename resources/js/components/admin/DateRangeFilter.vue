<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    action: { action: string; method: 'get' };
    filters: { from: string; to: string };
    hint: string;
}>();
</script>

<template>
    <Form
        v-bind="action"
        class="flex flex-wrap items-end gap-3"
        #default="{ errors, processing }"
    >
        <div class="grid gap-1.5">
            <Label for="from">From</Label>
            <Input
                id="from"
                name="from"
                type="date"
                :default-value="filters.from"
                class="w-40"
                required
            />
        </div>
        <div class="grid gap-1.5">
            <Label for="to">To</Label>
            <Input
                id="to"
                name="to"
                type="date"
                :default-value="filters.to"
                class="w-40"
                required
            />
        </div>
        <Button type="submit" variant="outline" :disabled="processing"
            >Show</Button
        >
        <p class="w-full text-sm text-muted-foreground">{{ hint }}</p>
        <InputError class="w-full" :message="errors.from ?? errors.to" />
    </Form>
</template>
