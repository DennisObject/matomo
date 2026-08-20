<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

use App\Matomo\Api\OptOutEmbedRequest;

interface OptOutEmbedCodeGenerator
{
    public function javascript(OptOutEmbedRequest $options): string;

    public function selfContained(OptOutEmbedRequest $options, string $language): string;
}
