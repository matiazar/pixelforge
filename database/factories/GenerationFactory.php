<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'form_token' => (string) Str::uuid(),
            'request_payload' => [
                'prompt' => 'A lighthouse at dawn',
                'size' => '1024x1024',
                'quality' => 'low',
                'reference_images' => [],
            ],
            'status' => GenerationStatus::Pending,
            'estimate_min_nano' => 14_000_000,
            'estimate_max_nano' => 21_906_250,
            'next_attempt_at' => now(),
        ];
    }

    public function failed(string $source = 'provider'): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Failed,
            'failure_source' => $source,
            'error_code' => 'generation_failed',
            'completed_at' => now(),
        ]);
    }
}
