<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class FeedbackRequest
{
    public function __construct(
        public ?string $featureName,
        public ?bool $like,
        public ?string $choice,
        public ?string $message,
        public ?string $question,
    ) {}
}
