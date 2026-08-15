<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Feedback\LaravelFeedbackMailer;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

class LaravelFeedbackMailerTest extends TestCase
{
    public function test_sends_plain_text_with_legacy_sender_and_recipients(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())
            ->method('raw')
            ->with('Feedback body', $this->isInstanceOf(\Closure::class))
            ->willReturnCallback(function (string $body, \Closure $configure): null {
                $email = new Email;
                $email->text($body);
                $configure(new Message($email));

                $this->assertSame('noreply@analytics.example', $email->getFrom()[0]->getAddress());
                $this->assertSame('Matomo Analytics', $email->getFrom()[0]->getName());
                $this->assertSame('feedback@example.test', $email->getTo()[0]->getAddress());
                $this->assertSame('Matomo Team', $email->getTo()[0]->getName());
                $this->assertSame('alice@example.test', $email->getReplyTo()[0]->getAddress());
                $this->assertSame('Subject', $email->getSubject());

                return null;
            });
        $feedback = new LaravelFeedbackMailer(
            $mailer,
            true,
            'noreply@{DOMAIN}',
            'Matomo Analytics',
        );

        $feedback->send(
            'feedback@example.test',
            'alice@example.test',
            'Subject',
            'Feedback body',
            'analytics.example',
        );
    }

    public function test_does_not_send_when_email_is_disabled_or_recipient_is_nobody(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->never())->method('raw');

        (new LaravelFeedbackMailer($mailer, false, 'noreply@example.test', 'Matomo'))
            ->send('feedback@example.test', '', 'Subject', 'Body', 'analytics.example');
        (new LaravelFeedbackMailer($mailer, true, 'noreply@example.test', 'Matomo'))
            ->send('nobody', '', 'Subject', 'Body', 'analytics.example');
    }
}
