<?php

declare(strict_types=1);

namespace App\Matomo\Api\Exceptions;

use RuntimeException;

final class MissingApiParameter extends RuntimeException
{
    public function __construct(string $parameter)
    {
        parent::__construct("Please specify a value for '{$parameter}'.");
    }
}
