<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class OptOutEmbedRequest
{
    public function __construct(
        public string $backgroundColor,
        public string $fontColor,
        public string $fontSize,
        public string $fontFamily,
        public bool $applyStyling,
        public bool $showIntro,
        public ?string $matomoUrl = null,
        public ?string $language = null,
        public string $cookiePath = '',
        public string $cookieDomain = '',
        public string $cookieSameSite = 'Lax',
    ) {}
}
