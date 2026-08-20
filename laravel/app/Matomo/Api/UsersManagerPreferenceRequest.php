<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerPreferenceRequest
{
    /** @param list<string> $preferenceNames */
    public function __construct(
        public ?string $userLogin,
        public ?string $preferenceName,
        public mixed $preferenceValue,
        public array $preferenceNames,
    ) {}
}
