<?php

namespace App\Modules\Tenancy\Infrastructure\Mail;

use App\Modules\Tenancy\Domain\Contracts\ClientActivationDelivery;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class MailClientActivationDelivery implements ClientActivationDelivery
{
    public function send(string $email, string $name, string $organization, string $url): ?string
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (in_array($transport, ['log', 'array'], true)) {
            if (app()->environment('local')) {
                return $url;
            }

            throw ValidationException::withMessages(['email' => __('platform.client_mail_unavailable')]);
        }
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            throw ValidationException::withMessages(['email' => __('platform.client_mail_unavailable')]);
        }

        Mail::raw(__('platform.client_mail_body', ['name' => $name, 'organization' => $organization, 'url' => $url]),
            fn (Message $message) => $message->to($email)->subject(__('platform.client_mail_subject')));

        return null;
    }
}
