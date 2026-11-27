<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Behind the VPS proxy the request arrives as http: without this, redirects go to http://
        // and the browser blocks them (Inertia stays on the page until F5). Unset = trust no proxy.
        $proxies = config('app.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
            // Only client IP and scheme. A forwarded Host would let a client point password reset
            // links (built from the request host) to another domain.
            TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        }

        // Admin area: report, reconciliation, global settings. is_admin is set only by the seeder.
        Gate::define('admin', fn (User $user): bool => $user->is_admin);

        // One forced sync per minute (it can make up to 20 GETs of the shared 50/min Pixelforge budget).
        // A second click goes back to the page with a message instead of a bare 429 screen.
        RateLimiter::for('reconciliation-sync', fn (Request $request): Limit => Limit::perMinute(1)
            ->by((string) $request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => back()->with(
                'status',
                'A sync already ran in the last minute. Try again in '.($headers['Retry-After'] ?? 60).' seconds.',
            )));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
