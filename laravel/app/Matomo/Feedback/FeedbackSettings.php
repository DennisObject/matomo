<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

interface FeedbackSettings
{
    public function recipient(): string;
}
