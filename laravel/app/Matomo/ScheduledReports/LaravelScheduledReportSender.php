<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

final readonly class LaravelScheduledReportSender implements ScheduledReportSender
{
    public function __construct(private Mailer $mailer) {}

    public function send(
        array $recipients,
        string $subject,
        string $html,
        ?RenderedScheduledReport $attachment = null,
    ): void {
        foreach ($recipients as $recipient) {
            $this->mailer->html($html, static function (Message $message) use (
                $recipient,
                $subject,
                $attachment,
            ): void {
                $message->to($recipient)->subject($subject);
                if ($attachment !== null) {
                    $message->attachData(
                        $attachment->contents,
                        'report.'.$attachment->extension,
                        ['mime' => $attachment->mimeType],
                    );
                }
            });
        }
    }
}
