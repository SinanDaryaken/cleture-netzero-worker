<?php

namespace App\Mail\Identity;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrganizationUserPasswordChangedMail extends Mailable
{
    public function __construct(public readonly string $organizationUserName)
    {
        $this->locale((string) config('identity.mail.fallback_locale'));
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('identity.password_changed.subject'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.organization-users.password-changed',
            text: 'mail.organization-users.password-changed-text',
            with: [
                'preheader' => __('identity.password_changed.preheader'),
            ],
        );
    }
}
