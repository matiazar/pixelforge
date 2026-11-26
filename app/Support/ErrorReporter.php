<?php

namespace App\Support;

use App\Mail\ErrorReportMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Mails unhandled exceptions to ADMIN_EMAIL.
 * Never in local/testing, one mail per distinct error every 10 minutes,
 * secrets redacted, and a failure here never breaks the app.
 */
class ErrorReporter
{
    private const THROTTLE_SECONDS = 600;

    public function report(Throwable $e): void
    {
        $recipient = config('app.admin_email');

        if (! $recipient || in_array(config('app.env'), ['local', 'testing'], true)) {
            return;
        }

        // A broken mail transport must not feed itself through this reporter.
        if ($e instanceof TransportExceptionInterface) {
            return;
        }

        $key = 'error-report:'.sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());

        try {
            if (Cache::has($key)) {
                return;
            }

            Mail::to($recipient)->queue(new ErrorReportMail(
                exceptionClass: $e::class,
                errorMessage: $this->redact($e->getMessage()),
                location: $e->getFile().':'.$e->getLine(),
                url: $this->describeRequest(),
                environment: (string) config('app.env'),
            ));

            // Throttle only after the mail is queued, so a failed attempt is retried next time.
            Cache::put($key, true, self::THROTTLE_SECONDS);
        } catch (Throwable $mailFailure) {
            Log::error('Error report could not be sent', ['reason' => $mailFailure->getMessage()]);
        }
    }

    /**
     * Route pattern only (e.g. "GET reset-password/{token}"): no query string,
     * no path parameters, so tokens and emails never leave the server.
     */
    private function describeRequest(): string
    {
        $route = request()->route();

        if ($route !== null) {
            return request()->method().' '.$route->uri();
        }

        return app()->runningInConsole() ? 'console' : request()->method().' (unmatched route)';
    }

    private function redact(string $text): string
    {
        $secrets = array_filter([
            config('services.pixelforge.api_key'),
            config('services.pixelforge.webhook_secret'),
            config('app.key'),
            config('database.connections.pgsql.password'),
        ]);

        return str_replace($secrets, '[redacted]', $text);
    }
}
