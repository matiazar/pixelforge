<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ErrorReportMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $exceptionClass,
        public string $errorMessage,
        public string $location,
        public string $url,
        public string $environment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] '.$this->environment.': '.class_basename($this->exceptionClass),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.error-report');
    }
}
