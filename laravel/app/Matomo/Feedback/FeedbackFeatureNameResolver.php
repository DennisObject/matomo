<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

interface FeedbackFeatureNameResolver
{
    public function englishName(string $name, string $language): string;
}
