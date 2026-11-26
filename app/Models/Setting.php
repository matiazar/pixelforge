<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global admin settings (key/value). Defaults live in config('pixelforge.settings').
 *
 * @property string $key
 * @property string $value
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public static function get(string $key): string
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value ?? (string) config("pixelforge.settings.{$key}");
    }

    public static function put(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
