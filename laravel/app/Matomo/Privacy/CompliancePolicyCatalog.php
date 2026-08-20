<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Localization\MatomoTranslator;

final readonly class CompliancePolicyCatalog
{
    private const string GUIDE_URL = 'https://matomo.org/faq/how-to/'
        .'how-do-i-configure-matomo-without-tracking-consent-for-french-visitors-cnil-exemption/'
        .'?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise'
        .'&mtm_medium=App.PrivacyManager.compliance';

    public function __construct(private MatomoTranslator $translator) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public function all(string $language): array
    {
        $link = '<a href="'.self::GUIDE_URL.'" target="_blank" rel="noreferrer noopener">';
        $description = $this->translator->translate(
            'General_ComplianceCNILDescription',
            $language,
            [$link, '</a>', $link, '</a>'],
        );
        $warning = $this->translator->translate('General_ComplianceCNILWarning', $language);
        if ($warning !== '') {
            $description .= '<br/>'.$warning;
        }

        return [[
            'id' => 'cnil_v1',
            'title' => $this->translator->translate('General_ComplianceCNILTitle', $language),
            'description' => $description,
        ]];
    }
}
