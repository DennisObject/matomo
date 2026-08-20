<?php

declare(strict_types=1);

namespace App\Matomo\Marketplace;

use RuntimeException;

final class MarketplaceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $responseStatus = 400)
    {
        parent::__construct($message);
    }
}
