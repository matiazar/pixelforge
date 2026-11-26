<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property int|null $delivery_attempt
 * @property Carbon $received_at
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['event_id', 'type', 'generation_id', 'payload', 'delivery_attempt', 'received_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
