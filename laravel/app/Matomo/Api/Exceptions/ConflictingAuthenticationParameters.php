<?php

declare(strict_types=1);

namespace App\Matomo\Api\Exceptions;

use RuntimeException;

final class ConflictingAuthenticationParameters extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Authentication parameters must not have conflicting values.');
    }
}
