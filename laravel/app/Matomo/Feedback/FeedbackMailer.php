<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

interface FeedbackMailer
{
    public function send(
        string $recipient,
        string $replyTo,
        string $subject,
        string $body,
        string $host,
    ): void;
}
