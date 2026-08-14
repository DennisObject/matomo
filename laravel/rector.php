<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
    ])
    ->withPhpSets()
    ->withTypeCoverageLevel(8)
    ->withDeadCodeLevel(8)
    ->withCodeQualityLevel(8)
    ->withCodingStyleLevel(8);
