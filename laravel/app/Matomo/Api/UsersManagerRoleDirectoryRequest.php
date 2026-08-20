<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerRoleDirectoryRequest
{
    public function __construct(
        public int $siteId,
        public ?int $limit,
        public int $offset,
        public ?string $search,
        public ?string $access,
        public ?string $status,
    ) {}
}
