<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserPreferenceRepository
{
    public function canonicalLogin(string $login): ?string;

    /** @return array{found: bool, value: mixed} */
    public function get(string $login, string $name): array;

    public function set(string $login, string $name, mixed $value): void;

    /** @param list<string> $names
     * @return array<string, array<string, mixed>>
     */
    public function forAllUsers(array $names): array;
}
