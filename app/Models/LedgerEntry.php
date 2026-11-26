<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only money movement, signed nano-USD. Written only by LedgerService.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property string $type topup | hold | release | charge
 * @property int $amount_nano
 * @property Carbon $created_at
 */
class LedgerEntry extends Model
{
    public const TOPUP = 'topup';

    public const HOLD = 'hold';

    public const RELEASE = 'release';

    public const CHARGE = 'charge';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'generation_id', 'type', 'amount_nano'];

    protected function casts(): array
    {
        return ['amount_nano' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
