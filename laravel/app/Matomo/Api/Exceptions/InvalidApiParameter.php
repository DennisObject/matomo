<?php

declare(strict_types=1);

namespace App\Matomo\Api\Exceptions;

use RuntimeException;

final class InvalidApiParameter extends RuntimeException
{
    public function __construct(string $parameter, ?string $message = null)
    {
        parent::__construct($message ?? "The API parameter [{$parameter}] must be a scalar value.");
    }
}
