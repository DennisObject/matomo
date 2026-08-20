<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

final readonly class LaravelUserInvitationNotifier implements UserInvitationNotifier
{
    public function __construct(private Mailer $mailer, private string $applicationUrl) {}

    public function notify(
        string $login,
        string $email,
        #[\SensitiveParameter] string $token,
        int $expiryDays,
    ): void {
        $url = rtrim($this->applicationUrl, '/').'/index.php?'.http_build_query([
            'module' => 'Login',
            'action' => 'acceptInvitation',
            'token' => $token,
        ]);
        $body = "You have been invited. This invitation expires in {$expiryDays} days.\n{$url}";
        $this->mailer->raw($body, static function (Message $message) use ($email, $login): void {
            $message->to($email, $login)->subject('User invitation');
        });
    }
}
