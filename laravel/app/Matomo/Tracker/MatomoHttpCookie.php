<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use Symfony\Component\HttpFoundation\Cookie;

final class MatomoHttpCookie extends Cookie
{
    public function __construct(private readonly IssuedTrackerCookie $cookie)
    {
        parent::__construct(
            $cookie->name,
            $cookie->value,
            $cookie->expiresAt,
            $cookie->path === '' ? '/' : $cookie->path,
            $cookie->domain === '' ? null : $cookie->domain,
            $cookie->secure,
            false,
            true,
            match (strtolower($cookie->sameSite)) {
                Cookie::SAMESITE_LAX => Cookie::SAMESITE_LAX,
                Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
                Cookie::SAMESITE_STRICT => Cookie::SAMESITE_STRICT,
                default => null,
            },
        );
    }

    public function __toString(): string
    {
        return $this->cookie->header();
    }
}
