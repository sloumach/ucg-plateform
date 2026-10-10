<?php

namespace App\Modules\Tenancy\Infrastructure\Mail;

use App\Support\Queue\JobContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrganizationInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $organizationName, public readonly string $frontendUrl, public readonly JobContext $context)
    {
        $this->onQueue(QueueName::Notifications->value);
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('tenancy.mail.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.organization-invitation');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->context->tags();
    }
}
