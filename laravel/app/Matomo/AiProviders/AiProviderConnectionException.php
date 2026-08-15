<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use RuntimeException;

final class AiProviderConnectionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $responseStatus = 502)
    {
        parent::__construct($message);
    }
}
