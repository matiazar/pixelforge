<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin setting "registration open": when closed, the Fortify register
 * screen sends people to login and the register POST is refused.
 */
class EnsureRegistrationIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('register', 'register.store') || Setting::get('registration_open') === '1') {
            return $next($request);
        }

        abort_if($request->isMethod('POST'), 403, 'Registration is closed.');

        return redirect()->route('login')->with('status', 'Registration is closed.');
    }
}
