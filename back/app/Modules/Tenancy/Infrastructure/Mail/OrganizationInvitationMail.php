<?php

namespace App\Modules\Tenancy\Infrastructure\Mail;

use App\Modules\Tenancy\Application\Contracts\TenantQueuedWork;
use App\Modules\Tenancy\Infrastructure\Queue\RestoreTenantJobContext;
use App\Support\Queue\JobContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrganizationInvitationMail extends Mailable implements ShouldQueue, TenantQueuedWork
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

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

    public function jobContext(): JobContext
    {
        return $this->context;
    }

    /** @return list<class-string<RestoreTenantJobContext>|class-string<AuthorizeInvitationMail>> */
    public function middleware(): array
    {
        return [RestoreTenantJobContext::class, AuthorizeInvitationMail::class];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->context->tags();
    }
}
