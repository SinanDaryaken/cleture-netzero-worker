<?php

namespace App\Mail\Identity;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrganizationUserEmailVerificationMail extends Mailable
{
    public function __construct(
        public readonly string $organizationUserName,
        #[\SensitiveParameter] public readonly string $actionUrl,
    ) {
        $this->locale((string) config('identity.mail.fallback_locale'));
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('identity.email_verification.subject'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.organization-users.email-verification',
            text: 'mail.organization-users.email-verification-text',
            with: [
                'preheader' => __('identity.email_verification.preheader'),
            ],
        );
    }
}
