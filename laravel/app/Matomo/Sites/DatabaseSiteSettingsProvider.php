<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Plugins\PluginState;
use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabaseSiteSettingsProvider implements SiteSettingsProvider
{
    public function __construct(
        private ConnectionInterface $connection,
        private SiteRepository $sites,
        private PluginState $plugins,
        private MatomoTranslator $translator,
    ) {}

    public function metadata(int $siteId, string $language): array
    {
        $site = $this->sites->details($siteId);
        $groups = [[
            'pluginName' => 'WebsiteMeasurable',
            'title' => 'WebsiteMeasurable',
            'settings' => $this->websiteSettings($siteId, $site, $language),
        ]];

        if ($this->plugins->isActivated('Live')) {
            $groups[] = [
                'pluginName' => 'Live',
                'title' => 'Live',
                'settings' => $this->liveSettings($siteId, $language),
            ];
        }

        if ($this->plugins->isActivated('ExampleSettingsPlugin')) {
            $groups[] = [
                'pluginName' => 'ExampleSettingsPlugin',
                'title' => 'ExampleSettingsPlugin',
                'settings' => [$this->setting(
                    'contact_email',
                    'Contact email addresses',
                    $this->stored($siteId, 'ExampleSettingsPlugin', 'contact_email', []),
                    [],
                    'array',
                    'textarea',
                )],
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, int|string|null>  $site
     * @return list<array<string, mixed>>
     */
    private function websiteSettings(int $siteId, array $site, string $language): array
    {
        $list = static fn (mixed $value): array => is_string($value) && $value !== ''
            ? explode(',', $value)
            : [];
        $yes = $this->translator->translate('General_Yes', $language);
        $no = $this->translator->translate('General_No', $language);
        $default = $this->translator->translate('General_Default', $language);

        return [
            $this->setting('urls', $this->translator->translate('SitesManager_Urls', $language), $this->sites->urls($siteId), [], 'array', 'textarea', ['cols' => '25', 'rows' => '3', 'placeholder' => "http://example.com/\nhttps://www.example.org/"]),
            $this->setting('exclude_unknown_urls', $this->translator->translate('SitesManager_OnlyMatchedUrlsAllowed', $language), (bool) ($site['exclude_unknown_urls'] ?? false), false, 'boolean', 'checkbox'),
            $this->setting('keep_url_fragment', $this->translator->translate('SitesManager_KeepURLFragmentsLong', $language), (string) ($site['keep_url_fragment'] ?? '0'), '0', 'string', 'select', availableValues: ['0' => "{$no} ({$default})", '1' => $yes, '2' => $no]),
            $this->setting('excluded_ips', $this->translator->translate('SitesManager_ExcludedIps', $language), $list($site['excluded_ips'] ?? ''), [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
            $this->setting('excluded_parameters', $this->translator->translate('SitesManager_ExcludedParameters', $language), $list($site['excluded_parameters'] ?? ''), [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
            $this->setting('excluded_user_agents', $this->translator->translate('SitesManager_ExcludedUserAgents', $language), $list($site['excluded_user_agents'] ?? ''), [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
            $this->setting('excluded_referrers', $this->translator->translate('SitesManager_ExcludedReferrers', $language), $list($site['excluded_referrers'] ?? ''), [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
            $this->setting('sitesearch', $this->translator->translate('Actions_SubmenuSitesearch', $language), (int) ($site['sitesearch'] ?? 1), 1, 'integer', 'select', availableValues: [1 => $this->translator->translate('SitesManager_EnableSiteSearch', $language), 0 => $this->translator->translate('SitesManager_DisableSiteSearch', $language)]),
            $this->setting('use_default_site_search_params', $this->translator->translate('SitesManager_SearchUseDefault', $language, ['', '']), $list($site['sitesearch_keyword_parameters'] ?? '') === [], true, 'boolean', 'checkbox', condition: '1 && sitesearch'),
            $this->setting('sitesearch_keyword_parameters', $this->translator->translate('SitesManager_SearchKeywordLabel', $language), $list($site['sitesearch_keyword_parameters'] ?? ''), [], 'array', 'text', condition: 'sitesearch && !use_default_site_search_params'),
            $this->setting('sitesearch_category_parameters', $this->translator->translate('SitesManager_SearchCategoryLabel', $language), $list($site['sitesearch_category_parameters'] ?? ''), [], 'array', 'text', condition: 'sitesearch && !use_default_site_search_params'),
            $this->setting('ecommerce', $this->translator->translate('Goals_Ecommerce', $language), (int) ($site['ecommerce'] ?? 0), 0, 'integer', 'select', availableValues: [0 => $this->translator->translate('SitesManager_NotAnEcommerceSite', $language), 1 => $this->translator->translate('SitesManager_EnableEcommerce', $language)]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function liveSettings(int $siteId, string $language): array
    {
        return [
            $this->setting('disable_visitor_log', $this->translator->translate('Live_DisableVisitsLogAndProfile', $language), (bool) $this->stored($siteId, 'Live', 'disable_visitor_log', false), false, 'boolean', 'checkbox'),
            $this->setting('disable_visitor_profile', $this->translator->translate('Live_DisableVisitorProfile', $language), (bool) $this->stored($siteId, 'Live', 'disable_visitor_profile', false), false, 'boolean', 'checkbox', condition: 'disable_visitor_log==0'),
        ];
    }

    /**
     * @param  array<string, string>  $uiControlAttributes
     * @param  array<int|string, string>  $availableValues
     * @return array<string, mixed>
     */
    private function setting(
        string $name,
        string $title,
        mixed $value,
        mixed $defaultValue,
        string $type,
        string $uiControl,
        array $uiControlAttributes = [],
        array $availableValues = [],
        ?string $condition = null,
    ): array {
        return [
            'name' => $name,
            'title' => $title,
            'value' => $value,
            'defaultValue' => $defaultValue,
            'type' => $type,
            'uiControl' => $uiControl,
            'uiControlAttributes' => $uiControlAttributes,
            'availableValues' => $availableValues === [] ? null : (object) $availableValues,
            'description' => null,
            'inlineHelp' => null,
            'introduction' => null,
            'condition' => $condition,
            'fullWidth' => false,
        ];
    }

    private function stored(int $siteId, string $plugin, string $name, mixed $default): mixed
    {
        $record = $this->connection->table('site_setting')
            ->select(['setting_value', 'json_encoded'])
            ->where('idsite', $siteId)
            ->where('plugin_name', $plugin)
            ->where('setting_name', $name)
            ->first();
        if ($record === null) {
            return $default;
        }

        $value = $record->setting_value ?? $default;
        if ((bool) ($record->json_encoded ?? false)) {
            try {
                return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $default;
            }
        }

        return $value;
    }
}
