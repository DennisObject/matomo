<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use RuntimeException;

final class SegmentWriteDenied extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
