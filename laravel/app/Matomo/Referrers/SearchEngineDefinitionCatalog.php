<?php

declare(strict_types=1);

namespace App\Matomo\Referrers;

interface SearchEngineDefinitionCatalog
{
    public function url(string $name): string;

    public function logo(string $url): string;

    public function backlink(string $url, string $keyword): ?string;

    /** @return array{name: string, keywords: string}|null */
    public function search(string $url): ?array;
}
