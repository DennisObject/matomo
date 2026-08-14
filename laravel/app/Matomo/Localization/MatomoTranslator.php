<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

interface MatomoTranslator
{
    /**
     * @param  list<bool|int|string>  $arguments
     */
    public function translate(string $key, string $language, array $arguments = []): string;
}
