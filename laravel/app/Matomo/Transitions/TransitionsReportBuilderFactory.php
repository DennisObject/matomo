<?php

declare(strict_types=1);

namespace App\Matomo\Transitions;

use Illuminate\Contracts\Container\Container;

final readonly class TransitionsReportBuilderFactory
{
    public function __construct(private Container $container) {}

    public function make(): TransitionsReportBuilder
    {
        return $this->container->make(TransitionsReportBuilder::class);
    }
}
