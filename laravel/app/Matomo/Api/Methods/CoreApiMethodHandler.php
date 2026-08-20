<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Events\ComparisonPagesCollecting;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Security\ClientIpResolver;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Piwik\Version;

final readonly class CoreApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private ClientIpResolver $clientIps,
        private PluginState $plugins,
        private Dispatcher $events,
        private MatomoTranslator $translator,
        private LanguageResolver $languages,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isVersionRequest()
            || $request->isPhpVersionRequest()
            || $request->isClientIpRequest()
            || $request->isComparisonPagesRequest()
            || $request->isPluginActivatedRequest()
            || $request->method === 'API.getAvailableMeasurableTypes';
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The version API handler does not support this method.');
        }

        if ($request->isPhpVersionRequest()) {
            return $this->phpVersion($request);
        }

        if ($request->isComparisonPagesRequest()) {
            $event = new ComparisonPagesCollecting;
            $this->events->dispatch($event);

            return $this->responses->values($request, $event->pages);
        }

        if ($request->method === 'API.getAvailableMeasurableTypes') {
            return $this->measurableTypes($request, $httpRequest);
        }

        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        return $this->responses->scalar(
            $request,
            match (true) {
                $request->isClientIpRequest() => $this->clientIps->resolve($httpRequest),
                $request->isPluginActivatedRequest() => $this->plugins->isActivated($request->pluginName ?? ''),
                default => Version::VERSION,
            },
        );
    }

    private function measurableTypes(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        $definitions = [
            'website' => [
                'plugin' => 'WebsiteMeasurable',
                'name' => 'Referrers_ColumnWebsite',
                'description' => 'WebsiteMeasurable_WebsiteDescription',
                'longDescription' => 'WebsiteMeasurable_WebsiteLongDescription',
                'howToSetupUrl' => '?module=CoreAdminHome&action=trackingCodeGenerator',
            ],
            'intranet' => [
                'plugin' => 'IntranetMeasurable',
                'name' => 'IntranetMeasurable_Intranet',
                'description' => 'IntranetMeasurable_IntranetDescription',
                'longDescription' => 'IntranetMeasurable_IntranetLongDescription',
                'howToSetupUrl' => '?module=CoreAdminHome&action=trackingCodeGenerator',
            ],
            'mobileapp' => [
                'plugin' => 'MobileAppMeasurable',
                'name' => 'MobileAppMeasurable_MobileApp',
                'description' => 'MobileAppMeasurable_MobileAppDescription',
                'longDescription' => 'MobileAppMeasurable_MobileAppLongDescription',
                'howToSetupUrl' => 'https://developer.matomo.org/guides/tracking-api-clients#mobile-sdks',
            ],
        ];
        $types = [];
        $language = $this->languages->resolve($httpRequest, $request->authentication);
        foreach ($definitions as $id => $definition) {
            if (! $this->plugins->isActivated($definition['plugin'])) {
                continue;
            }

            $types[] = [
                'id' => $id,
                'name' => $this->translator->translate($definition['name'], $language),
                'description' => $this->translator->translate($definition['description'], $language),
                'longDescription' => $this->translator->translate($definition['longDescription'], $language),
                'howToSetupUrl' => $definition['howToSetupUrl'],
                'settings' => $this->measurableSettings($definition['plugin'], $language),
            ];
        }

        return $this->responses->structured($request, $types);
    }

    /** @return list<array<string, mixed>> */
    private function measurableSettings(string $plugin, string $language): array
    {
        $yes = $this->translator->translate('General_Yes', $language);
        $no = $this->translator->translate('General_No', $language);
        $default = $this->translator->translate('General_Default', $language);

        return [[
            'pluginName' => $plugin,
            'title' => $plugin,
            'settings' => [
                $this->measurableSetting('urls', $this->translator->translate('SitesManager_Urls', $language), [], [], 'array', 'textarea', ['cols' => '25', 'rows' => '3', 'placeholder' => "http://example.com/\nhttps://www.example.org/"]),
                $this->measurableSetting('exclude_unknown_urls', $this->translator->translate('SitesManager_OnlyMatchedUrlsAllowed', $language), false, false, 'boolean', 'checkbox'),
                $this->measurableSetting('keep_url_fragment', $this->translator->translate('SitesManager_KeepURLFragmentsLong', $language), '0', '0', 'string', 'select', availableValues: ['0' => "{$no} ({$default})", '1' => $yes, '2' => $no]),
                $this->measurableSetting('excluded_ips', $this->translator->translate('SitesManager_ExcludedIps', $language), [], [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
                $this->measurableSetting('excluded_parameters', $this->translator->translate('SitesManager_ExcludedParameters', $language), [], [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
                $this->measurableSetting('excluded_user_agents', $this->translator->translate('SitesManager_ExcludedUserAgents', $language), [], [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
                $this->measurableSetting('excluded_referrers', $this->translator->translate('SitesManager_ExcludedReferrers', $language), [], [], 'array', 'textarea', ['cols' => '20', 'rows' => '4']),
                $this->measurableSetting('sitesearch', $this->translator->translate('Actions_SubmenuSitesearch', $language), 1, 1, 'integer', 'select', availableValues: [1 => $this->translator->translate('SitesManager_EnableSiteSearch', $language), 0 => $this->translator->translate('SitesManager_DisableSiteSearch', $language)]),
                $this->measurableSetting('use_default_site_search_params', $this->translator->translate('SitesManager_SearchUseDefault', $language, ['', '']), true, true, 'boolean', 'checkbox', condition: '1 && sitesearch'),
                $this->measurableSetting('sitesearch_keyword_parameters', $this->translator->translate('SitesManager_SearchKeywordLabel', $language), [], [], 'array', 'text', condition: 'sitesearch && !use_default_site_search_params'),
                $this->measurableSetting('sitesearch_category_parameters', $this->translator->translate('SitesManager_SearchCategoryLabel', $language), [], [], 'array', 'text', condition: 'sitesearch && !use_default_site_search_params'),
                $this->measurableSetting('ecommerce', $this->translator->translate('Goals_Ecommerce', $language), 0, 0, 'integer', 'select', availableValues: [0 => $this->translator->translate('SitesManager_NotAnEcommerceSite', $language), 1 => $this->translator->translate('SitesManager_EnableEcommerce', $language)]),
            ],
        ]];
    }

    /**
     * @param  array<string, string>  $uiControlAttributes
     * @param  array<int|string, string>  $availableValues
     * @return array<string, mixed>
     */
    private function measurableSetting(
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

    private function phpVersion(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        return $this->responses->row($request, [
            'version' => PHP_VERSION,
            'major' => PHP_MAJOR_VERSION,
            'minor' => PHP_MINOR_VERSION,
            'release' => PHP_RELEASE_VERSION,
            'versionId' => PHP_VERSION_ID,
            'extra' => PHP_EXTRA_VERSION,
        ]);
    }
}
