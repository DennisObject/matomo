<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredFeedbackSettings implements FeedbackSettings
{
    public function __construct(private InstallationConfig $configuration) {}

    public function recipient(): string
    {
        return $this->configuration->feedbackEmailAddress();
    }
}
