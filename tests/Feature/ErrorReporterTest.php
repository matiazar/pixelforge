<?php

use App\Mail\ErrorReportMail;
use App\Support\ErrorReporter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    Mail::fake();
    config(['app.admin_email' => 'admin@example.com']);
});

it('mails the admin in production', function () {
    config(['app.env' => 'production']);

    app(ErrorReporter::class)->report(new RuntimeException('boom'));

    Mail::assertQueued(ErrorReportMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
});

it('does not mail in local or testing', function (string $env) {
    config(['app.env' => $env]);

    app(ErrorReporter::class)->report(new RuntimeException('boom'));

    Mail::assertNothingQueued();
})->with(['local', 'testing']);

it('sends one mail per repeated error within the throttle window', function () {
    config(['app.env' => 'production']);
    $reporter = app(ErrorReporter::class);
    $e = new RuntimeException('boom');

    $reporter->report($e);
    $reporter->report($e);

    Mail::assertQueuedCount(1);
});

it('redacts the provider api key from the message', function () {
    config(['app.env' => 'production', 'services.pixelforge.api_key' => 'pf_secret_123']);

    app(ErrorReporter::class)->report(new RuntimeException('auth failed for pf_secret_123'));

    Mail::assertQueued(ErrorReportMail::class, fn ($mail) => ! str_contains($mail->errorMessage, 'pf_secret_123'));
});

it('does not report mail transport failures', function () {
    config(['app.env' => 'production']);

    app(ErrorReporter::class)->report(new TransportException('smtp down'));

    Mail::assertNothingQueued();
});

it('does not include query strings or path values of the request', function () {
    config(['app.env' => 'production']);
    Route::get('/boom/{token}', fn () => throw new RuntimeException('boom'));

    $this->get('/boom/secret-token?email=user@example.com');

    Mail::assertQueued(ErrorReportMail::class, fn ($mail) => $mail->url === 'GET boom/{token}');
});
