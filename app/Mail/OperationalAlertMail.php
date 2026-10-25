<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OperationalAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $alertType, public string $alertMessage) {}

    public function build(): self
    {
        return $this->subject('HX Pay: '.str_replace('.', ' ', $this->alertType))->view('emails.alert');
    }
}
