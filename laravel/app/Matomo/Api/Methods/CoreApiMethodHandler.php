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
                'settings' => [],
            ];
        }

        return $this->responses->structured($request, $types);
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
