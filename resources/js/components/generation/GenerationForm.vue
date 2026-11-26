<script setup lang="ts">
import { Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { LoaderCircle, Plus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { index as credits } from '@/actions/App/Http/Controllers/CreditsController';
import {
    estimate,
    store,
} from '@/actions/App/Http/Controllers/GenerationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { GenerationOptions, PriceRange } from '@/types';

const props = defineProps<{ options: GenerationOptions }>();

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const newToken = () => crypto.randomUUID();

const form = useForm({
    prompt: '',
    size: props.options.sizes[0],
    quality: props.options.qualities[1] ?? props.options.qualities[0],
    reference_images: [] as string[],
    form_token: newToken(),
});

// --- live estimate (server computes it: no money math in the browser) ---
const quote = useHttp({
    prompt: '',
    size: form.size,
    quality: form.quality,
    reference_count: 0,
});
const page = usePage();
const range = ref<PriceRange | null>(null);
const estimateFailed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const refreshEstimate = () => {
    quote.prompt = form.prompt;
    quote.size = form.size;
    quote.quality = form.quality;
    quote.reference_count = form.reference_images.length;
    quote.cancel();
    quote
        .post(estimate.url())
        .then((response) => {
            range.value = response as PriceRange;
            estimateFailed.value = false;
        })
        .catch(() => {
            // A 422 is an invalid draft (the field counter already says why).
            // Anything else (throttle, network, server) means the shown price may be stale.
            estimateFailed.value = !quote.hasErrors;
        });
};

watch(
    () => [form.prompt, form.size, form.quality, form.reference_images.length],
    () => {
        clearTimeout(timer);
        timer = setTimeout(refreshEstimate, 400);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));

const promptLength = computed(() => [...form.prompt].length);
const promptTooLong = computed(
    () => promptLength.value > props.options.max_prompt,
);
const canAddReference = computed(
    () => form.reference_images.length < props.options.max_references,
);

const addReference = () => {
    if (canAddReference.value) {
        form.reference_images.push('');
    }
};
const removeReference = (index: number) =>
    form.reference_images.splice(index, 1);

const referenceError = (index: number) =>
    (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];

const submit = () => {
    form.reference_images = form.reference_images.filter(
        (url) => url.trim() !== '',
    );
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'reference_images');
            form.form_token = newToken();
        },
    });
};

const blockingError = computed(
    () =>
        (form.errors as Record<string, string | undefined>).balance ??
        (form.errors as Record<string, string | undefined>).daily_limit,
);
</script>

<template>
    <form
        class="grid gap-6 lg:grid-cols-[1fr_20rem] lg:gap-8"
        @submit.prevent="submit"
    >
        <div class="grid content-start gap-6">
            <div class="grid gap-2">
                <div class="flex items-baseline justify-between">
                    <Label for="prompt">Describe the image</Label>
                    <span
                        :class="[
                            'text-xs tabular-nums',
                            promptTooLong
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        ]"
                    >
                        {{ promptLength }} / {{ options.max_prompt }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    name="prompt"
                    rows="4"
                    required
                    placeholder="A lighthouse on a rocky coast at dawn, soft fog, film photo"
                    :aria-invalid="!!form.errors.prompt || promptTooLong"
                    class="w-full resize-y rounded-md border border-input bg-card px-3 py-2 text-[15px] leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30 aria-invalid:border-destructive"
                />
                <InputError :message="form.errors.prompt" />
            </div>

            <div class="grid gap-6">
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Shape</legend>
                    <div class="grid grid-cols-3 gap-1 rounded-md bg-muted p-1">
                        <label
                            v-for="size in options.sizes"
                            :key="size"
                            class="cursor-pointer rounded px-2 py-1.5 text-center text-sm transition-colors has-checked:bg-card has-checked:font-medium has-checked:shadow-xs has-focus-visible:ring-2 has-focus-visible:ring-ring"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size"
                                class="sr-only"
                            />
                            {{ sizeLabels[size] ?? size }}
                            <span
                                class="block text-[11px] text-muted-foreground tabular-nums"
                            >
                                {{ size }}
                            </span>
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-1 rounded-md bg-muted p-1">
                        <label
                            v-for="quality in options.qualities"
                            :key="quality"
                            class="flex cursor-pointer items-center justify-center rounded px-2 py-1.5 text-sm capitalize transition-colors has-checked:bg-card has-checked:font-medium has-checked:shadow-xs has-focus-visible:ring-2 has-focus-visible:ring-ring"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality"
                                class="sr-only"
                            />
                            {{ quality }}
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>
            </div>

            <div class="grid gap-2">
                <p class="text-sm font-medium">
                    Reference images
                    <span class="font-normal text-muted-foreground">
                        (optional, up to {{ options.max_references }} links)
                    </span>
                </p>
                <div
                    v-for="(_url, index) in form.reference_images"
                    :key="index"
                    class="grid gap-1"
                >
                    <div class="flex gap-2">
                        <input
                            v-model="form.reference_images[index]"
                            class="h-9 w-full min-w-0 rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30 aria-invalid:border-destructive"
                            type="url"
                            inputmode="url"
                            placeholder="https://example.com/photo.jpg"
                            :aria-label="`Reference image ${index + 1}`"
                            :aria-invalid="!!referenceError(index)"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove reference image ${index + 1}`"
                            @click="removeReference(index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <InputError :message="referenceError(index)" />
                </div>
                <div>
                    <Button
                        v-if="canAddReference"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addReference"
                    >
                        <Plus aria-hidden="true" />
                        Add a reference link
                    </Button>
                </div>
                <InputError :message="form.errors.reference_images" />
            </div>
        </div>

        <!-- The price ticket: what this request can cost before it is sent. -->
        <aside
            class="relative self-start rounded-lg border bg-card shadow-xs lg:sticky lg:top-4"
            aria-live="polite"
        >
            <div class="p-5">
                <p class="text-sm text-muted-foreground">
                    This image will cost
                </p>
                <p
                    class="mt-1 text-2xl font-semibold tracking-tight tabular-nums transition-opacity motion-reduce:transition-none"
                    :class="{ 'opacity-50': quote.processing }"
                >
                    <template v-if="range">
                        ${{ range.min }}
                        <span
                            class="text-base font-normal text-muted-foreground"
                            >to</span
                        >
                        ${{ range.max }}
                    </template>
                    <template v-else>&nbsp;</template>
                </p>
                <p
                    v-if="estimateFailed"
                    class="mt-2 text-sm text-waiting"
                    role="status"
                >
                    Could not update the price. It is checked again when you
                    send.
                </p>
                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    We hold the maximum from your balance when you send it. You
                    pay the real cost once the image is ready, and the rest is
                    released. Failed images are free.
                </p>
            </div>

            <div class="relative" aria-hidden="true">
                <div class="mx-5 border-t-2 border-dashed" />
                <span
                    class="absolute top-1/2 -left-px size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border bg-background [clip-path:inset(0_0_0_50%)]"
                />
                <span
                    class="absolute top-1/2 -right-px size-4 translate-x-1/2 -translate-y-1/2 rounded-full border bg-background [clip-path:inset(0_50%_0_0)]"
                />
            </div>

            <div class="grid gap-3 p-5">
                <p
                    v-if="blockingError"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ blockingError }}
                </p>
                <p
                    class="flex items-baseline justify-between text-sm tabular-nums"
                >
                    <span class="text-muted-foreground">Your balance</span>
                    <Link
                        :href="credits()"
                        class="font-medium underline-offset-4 hover:underline"
                        >${{ page.props.balance }}</Link
                    >
                </p>
                <Button
                    type="submit"
                    size="lg"
                    class="w-full active:scale-[0.98] motion-reduce:active:scale-100"
                    :disabled="
                        form.processing || promptTooLong || !form.prompt.trim()
                    "
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    Generate image
                </Button>
            </div>
        </aside>
    </form>
</template>
