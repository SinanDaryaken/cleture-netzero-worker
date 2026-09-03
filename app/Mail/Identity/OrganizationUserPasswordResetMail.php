<?php

namespace App\Mail\Identity;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrganizationUserPasswordResetMail extends Mailable
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
            subject: __('identity.password_reset.subject'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.organization-users.password-reset',
            text: 'mail.organization-users.password-reset-text',
            with: [
                'preheader' => __('identity.password_reset.preheader'),
            ],
        );
    }
}
