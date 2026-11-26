<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminRequest;
use App\Http\Requests\Admin\UpdateAppSettingsRequest;
use App\Models\Setting;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(AdminRequest $request): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'timezone' => Setting::get('timezone'),
                'daily_limit' => (int) Setting::get('daily_limit'),
                'registration_open' => Setting::get('registration_open') === '1',
                'markup_percent' => (int) Setting::get('markup_percent'),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(UpdateAppSettingsRequest $request): RedirectResponse
    {
        Setting::putMany([
            'timezone' => $request->string('timezone')->toString(),
            'daily_limit' => (string) $request->integer('daily_limit'),
            'registration_open' => $request->boolean('registration_open') ? '1' : '0',
            'markup_percent' => (string) $request->integer('markup_percent'),
        ]);

        return back()->with('status', 'Settings saved.');
    }
}
