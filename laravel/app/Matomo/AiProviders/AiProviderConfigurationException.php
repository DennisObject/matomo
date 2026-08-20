<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use InvalidArgumentException;

final class AiProviderConfigurationException extends InvalidArgumentException
{
    /**
     * @param  list<bool|int|string>  $translationArguments
     */
    public function __construct(
        public readonly ?string $translationKey,
        public readonly array $translationArguments = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : ($translationKey ?? 'Invalid AI provider configuration.'));
    }
}
