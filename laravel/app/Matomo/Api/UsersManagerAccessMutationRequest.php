<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerAccessMutationRequest
{
    /** @param list<string> $entries
     * @param  list<string>  $siteIds
     */
    public function __construct(
        public string $userLogin,
        public array $entries,
        public bool $entriesWereArray,
        public array $siteIds,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
