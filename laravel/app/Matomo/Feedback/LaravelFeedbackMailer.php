<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

final readonly class LaravelFeedbackMailer implements FeedbackMailer
{
    public function __construct(
        private Mailer $mailer,
        private bool $enabled,
        private string $fromAddress,
        private string $fromName,
    ) {}

    public function send(
        string $recipient,
        string $replyTo,
        string $subject,
        string $body,
        string $host,
    ): void {
        if (! $this->enabled || $recipient === 'nobody') {
            return;
        }

        $fromAddress = str_replace('{DOMAIN}', $host, $this->fromAddress);
        $fromName = $this->fromName !== '' ? $this->fromName : 'Matomo';

        $this->mailer->raw($body, static function (Message $message) use (
            $recipient,
            $replyTo,
            $subject,
            $fromAddress,
            $fromName,
        ): void {
            $message->from($fromAddress, $fromName);
            $message->to($recipient, 'Matomo Team');

            if ($replyTo !== '') {
                $message->replyTo($replyTo);
            }

            $message->subject($subject);
        });
    }
}
