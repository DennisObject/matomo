<?php

declare(strict_types=1);

namespace App\Matomo\Overlay;

use Illuminate\Contracts\Container\Container;

final readonly class OverlayReportContextFactory
{
    public function __construct(private Container $container) {}

    public function make(): OverlayReportContext
    {
        return new OverlayReportContext(
            followingPagesLimit: $this->container->make(OverlaySettings::class)->followingPagesLimit(),
            pageUrls: $this->container->make(PageUrlQueryParameterFilter::class),
        );
    }
}
