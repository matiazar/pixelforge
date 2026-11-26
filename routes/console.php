<?php

use App\Jobs\DispatchPendingGenerations;
use App\Jobs\SyncOpenGenerations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DispatchPendingGenerations)->everyTenSeconds()->withoutOverlapping();
Schedule::job(new SyncOpenGenerations)->everyTenSeconds()->withoutOverlapping();
