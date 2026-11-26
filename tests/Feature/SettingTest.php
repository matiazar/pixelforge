<?php

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('falls back to the config default when a setting was never saved', function () {
    expect(Setting::get('timezone'))->toBe('UTC')
        ->and(Setting::get('daily_limit'))->toBe('20');
});

it('reads the settings from the cache, not the database, until they are edited', function () {
    Setting::put('timezone', 'America/Argentina/Buenos_Aires');
    expect(Setting::get('timezone'))->toBe('America/Argentina/Buenos_Aires');

    DB::table('settings')->where('key', 'timezone')->update(['value' => 'Europe/Madrid']);
    DB::enableQueryLog();

    expect(Setting::get('timezone'))->toBe('America/Argentina/Buenos_Aires')
        ->and(DB::getQueryLog())->toBe([]);
});

it('re-reads the settings after saving several at once', function () {
    Setting::get('timezone'); // warm the cache

    Setting::putMany(['timezone' => 'Asia/Tokyo', 'daily_limit' => '3']);

    expect(Setting::get('timezone'))->toBe('Asia/Tokyo')
        ->and(Setting::get('daily_limit'))->toBe('3');
});

it('saves several settings all or nothing', function () {
    Setting::put('timezone', 'Asia/Tokyo');

    // value is NOT NULL: the second write fails and the first is rolled back.
    expect(fn () => Setting::putMany(['timezone' => 'Europe/Madrid', 'daily_limit' => null]))
        ->toThrow(QueryException::class);

    expect(Setting::get('timezone'))->toBe('Asia/Tokyo');
});

it('never serves values a slow reader cached from before the last save', function () {
    Setting::put('registration_open', '1');
    $staleKey = Setting::cacheKey();

    Setting::put('registration_open', '0');
    // A request that read the old rows before the commit stores them late.
    Cache::forever($staleKey, ['registration_open' => '1']);

    expect(Setting::get('registration_open'))->toBe('0');
});
