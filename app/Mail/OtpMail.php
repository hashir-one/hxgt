<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your HX UPI Gateway verification code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            text: 'emails.text.otp',
            with: [
                'code' => $this->code,
                'purpose' => $this->purpose,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
