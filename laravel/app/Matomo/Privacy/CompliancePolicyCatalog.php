<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

final class CompliancePolicyCatalog
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public function all(): array
    {
        return [[
            'id' => 'cnil_v1',
            'title' => 'CNIL Website Analytics Compliance',
            'description' => 'Shows how the analytics configuration aligns with CNIL guidance for consent-exempt audience measurement. This information is not legal advice and does not guarantee compliance.',
        ]];
    }
}
