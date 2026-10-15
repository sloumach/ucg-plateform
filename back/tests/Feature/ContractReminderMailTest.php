<?php

namespace Tests\Feature;

use App\Modules\Contracts\Infrastructure\Mail\LaravelContractReminderMailer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Mockery;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ContractReminderMailTest extends TestCase
{
    public function test_smtp_requires_explicit_enablement_and_a_bounded_timeout(): void
    {
        $adapter = app(LaravelContractReminderMailer::class);
        config(['contracts.reminders.email_enabled' => false]);
        $this->assertFalse($adapter->available());
        config(['contracts.reminders.email_enabled' => true, 'contracts.reminders.mailer' => 'log']);
        $this->assertFalse($adapter->available());
        config(['contracts.reminders.mailer' => 'smtp', 'mail.mailers.smtp.timeout' => null]);
        $this->assertFalse($adapter->available());
        config(['mail.mailers.smtp.timeout' => 6]);
        $this->assertFalse($adapter->available());
        config(['mail.mailers.smtp.timeout' => 5]);
        $this->assertTrue($adapter->available());
    }

    public function test_real_laravel_message_contains_only_a_generic_prompt_and_stable_technical_key(): void
    {
        $transport = new ArrayTransport;
        $adapter = $this->adapter($transport);
        $key = hash('sha256', 'test-occurrence');
        $adapter->send('verified@example.test', $key);
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $this->assertSame('verified@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('Un rappel vous attend dans UCG', $message->getSubject());
        $body = $message->getTextBody();
        $this->assertIsString($body);
        $this->assertStringContainsString('Mes rappels de contrats', $body);
        $this->assertSame($key, $message->getHeaders()->get('X-UCG-Reminder')?->getBodyAsString());
        $this->assertSame([], $message->getAttachments());
        $this->assertNull($message->getHtmlBody());
    }

    public function test_laravel_listener_cancellation_is_never_reported_as_an_accepted_email(): void
    {
        $transport = new ArrayTransport;
        $adapter = $this->adapter($transport);
        app(Dispatcher::class)->listen(MessageSending::class, fn (): bool => false);
        $this->expectException(RuntimeException::class);
        $adapter->send('verified@example.test', hash('sha256', 'test'));
    }

    private function adapter(ArrayTransport $transport): LaravelContractReminderMailer
    {
        config(['contracts.reminders.email_enabled' => true, 'contracts.reminders.mailer' => 'smtp', 'mail.mailers.smtp.timeout' => 5]);
        $mailer = new Mailer('contract-test', app(Factory::class), $transport, app(Dispatcher::class));
        $mailer->alwaysFrom('no-reply@example.test', 'UCG');
        $manager = Mockery::mock(MailManager::class);
        $this->assertInstanceOf(MailManager::class, $manager);
        $manager->shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);

        return new LaravelContractReminderMailer($manager, app(Repository::class));
    }
}
