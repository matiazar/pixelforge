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
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(UpdateAppSettingsRequest $request): RedirectResponse
    {
        Setting::put('timezone', $request->string('timezone')->toString());
        Setting::put('daily_limit', (string) $request->integer('daily_limit'));
        Setting::put('registration_open', $request->boolean('registration_open') ? '1' : '0');

        return back()->with('status', 'Settings saved.');
    }
}
