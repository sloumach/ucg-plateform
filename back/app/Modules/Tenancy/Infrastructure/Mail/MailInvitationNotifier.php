<?php

namespace App\Modules\Tenancy\Infrastructure\Mail;

use App\Modules\Tenancy\Domain\Contracts\InvitationNotifier;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Factory;

final readonly class MailInvitationNotifier implements InvitationNotifier
{
    public function __construct(private Factory $mail, private Repository $config) {}

    public function notify(OrganizationInvitation $invitation, string $organizationName, string $requestId): void
    {
        $this->mail->mailer()->send((new OrganizationInvitationMail(
            $organizationName, (string) $this->config->get('app.frontend_url'), new JobContext($requestId, $invitation->organization_id),
        ))->to($invitation->email));
    }
}
