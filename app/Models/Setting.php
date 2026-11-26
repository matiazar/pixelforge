<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Global admin settings (key/value). Defaults live in config('pixelforge.settings').
 *
 * Read on most requests, so all rows are cached with no expiry under a
 * versioned key. Saving through put()/putMany() moves the version after the
 * commit: a slow reader that loaded the old rows can only write them under the
 * previous version, which nobody reads again.
 *
 * @property string $key
 * @property string $value
 */
class Setting extends Model
{
    private const VERSION_KEY = 'settings:version';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public static function get(string $key): string
    {
        /** @var array<string, string> $saved */
        $saved = Cache::rememberForever(static::cacheKey(), fn () => static::query()->pluck('value', 'key')->all());

        return $saved[$key] ?? (string) config("pixelforge.settings.{$key}");
    }

    public static function put(string $key, string $value): void
    {
        static::putMany([$key => $value]);
    }

    /**
     * All or nothing. The cache version moves only once the outermost
     * transaction commits, so no reader can cache the old rows as current.
     *
     * @param  array<string, string>  $values
     */
    public static function putMany(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }

            DB::afterCommit(fn () => Cache::forever(self::VERSION_KEY, (string) Str::ulid()));
        });
    }

    /**
     * Cache key of the current settings map.
     */
    public static function cacheKey(): string
    {
        return 'settings:'.Cache::get(self::VERSION_KEY, 'initial');
    }
}
