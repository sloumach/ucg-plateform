<?php

namespace App\Modules\Contracts\Infrastructure\Mail;

use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use RuntimeException;

final readonly class LaravelContractReminderMailer implements ContractReminderMailer
{
    public function __construct(private MailManager $mail, private Repository $config) {}

    public function available(): bool
    {
        $mailer = $this->config->get('contracts.reminders.mailer', 'smtp');

        return $this->config->get('contracts.reminders.email_enabled') === true && is_string($mailer)
            && $this->config->get('mail.mailers.'.$mailer.'.transport') === 'smtp'
            && is_numeric($this->config->get('mail.mailers.'.$mailer.'.timeout'))
            && (float) $this->config->get('mail.mailers.'.$mailer.'.timeout') > 0
            && (float) $this->config->get('mail.mailers.'.$mailer.'.timeout') <= 5;
    }

    public function send(string $email, string $idempotencyKey): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Contract reminder SMTP unavailable.');
        }
        // No contract title, dates, names, amounts, attachments, or private access token in e-mail.
        $text = __('managed_contracts.reminder_email_body');
        $sent = $this->mail->mailer($this->config->get('contracts.reminders.mailer', 'smtp'))->raw($text, function (Message $message) use ($email, $idempotencyKey): void {
            $message->to($email)->subject(__('managed_contracts.reminder_email_subject'));
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-UCG-Reminder', $idempotencyKey);
        });
        if ($sent === null) {
            throw new RuntimeException('Contract reminder transport did not acknowledge delivery.');
        }
    }
}
