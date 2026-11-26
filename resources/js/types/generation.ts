export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/** Money comes formatted from the server: never parse it into a number. */
export type PriceRange = { min: string; max: string };

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string | null;
    quality: string | null;
    reference_count: number;
    estimate: PriceRange;
    price: string | null;
    error_message: string | null;
    image_url: string | null;
    image_expired: boolean;
    is_retry: boolean;
    can_retry: boolean;
    created_at: string | null;
    completed_at: string | null;
};

export type GenerationOptions = {
    sizes: string[];
    qualities: string[];
    max_references: number;
    max_prompt: number;
};

export type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
    links: {
        prev: string | null;
        next: string | null;
    };
};
