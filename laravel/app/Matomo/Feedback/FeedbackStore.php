<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

interface FeedbackStore
{
    public function emailForLogin(string $login): string;

    public function setNextReminder(string $login, string $date): void;
}
