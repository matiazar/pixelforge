<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property int $user_id
 * @property string $form_token
 * @property string|null $retry_of_id
 * @property string|null $provider_id
 * @property string|null $api_key_fingerprint
 * @property array<string, mixed> $request_payload
 * @property GenerationStatus $status
 * @property string|null $failure_source
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property int $estimate_min_nano
 * @property int $estimate_max_nano
 * @property int|null $cost_nano
 * @property int|null $price_nano
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property int $attempts
 * @property bool $ambiguous
 * @property CarbonInterface|null $attempt_started_at
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface|null $provider_created_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * Everything is written by services, never from request input directly.
     *
     * @var list<string>
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'status' => GenerationStatus::class,
            'rates' => 'array',
            'usage' => 'array',
            'estimate_min_nano' => 'integer',
            'estimate_max_nano' => 'integer',
            'cost_nano' => 'integer',
            'price_nano' => 'integer',
            'attempts' => 'integer',
            'ambiguous' => 'boolean',
            'image_url_expires_at' => 'datetime',
            'attempt_started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'retry_of_id');
    }

    /**
     * @return HasOne<Generation, $this>
     */
    public function retriedBy(): HasOne
    {
        return $this->hasOne(Generation::class, 'retry_of_id');
    }
}
