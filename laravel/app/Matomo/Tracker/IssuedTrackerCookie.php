<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class IssuedTrackerCookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int $expiresAt,
        public string $path,
        public string $domain,
        public bool $secure,
        public string $sameSite,
    ) {}

    public function header(): string
    {
        return MatomoCookie::header(
            $this->name,
            $this->value,
            $this->expiresAt,
            $this->path,
            $this->domain,
            $this->secure,
            $this->sameSite,
        );
    }
}
