<?php

declare(strict_types=1);

namespace App\Matomo\Users\Events;

final class AccessCapabilitiesCollecting
{
    /**
     * @param  list<array{id: string, name: string, description: string, helpUrl: string, includedInRoles: list<string>, category: string}>  $capabilities
     */
    public function __construct(public array $capabilities = []) {}
}
