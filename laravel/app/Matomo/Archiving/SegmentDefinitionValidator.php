<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class SegmentDefinitionValidator
{
    public function __construct(private SegmentExpressionParser $parser = new SegmentExpressionParser) {}

    public function validate(?string $segment): void
    {
        $this->parser->parse($segment);
    }
}
