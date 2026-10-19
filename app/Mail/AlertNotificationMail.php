<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertNotificationMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $bodyText) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.alert-notification', text: 'mail.alert-notification-text');
    }
}
