<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Tracker\TrackerInstallationCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class JsTrackerInstallCheckApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private TrackerInstallationCheck $checks,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isJsTrackerInstallCheckRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The JS tracker installation API handler does not support this request.');
        }

        $parameters = $request->jsTrackerInstallCheck
            ?? throw new LogicException('The JS tracker installation parameters were not parsed.');
        $siteId = $parameters->siteId;

        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        $mainUrl = $this->sites->mainUrl($siteId) ?? '';

        if ($request->method === 'JsTrackerInstallCheck.wasJsTrackerInstallTestSuccessful') {
            if ($parameters->nonce !== ''
                && preg_match('/^[a-f0-9]{32}$/iD', $parameters->nonce) !== 1) {
                return $this->responses->error($request, 'The provided nonce is invalid.', 400);
            }

            return $this->responses->row($request, [
                'isSuccess' => $this->checks->successful($siteId, $parameters->nonce, $mainUrl),
                'mainUrl' => $mainUrl,
            ]);
        }

        $url = $parameters->url;

        if ($url !== '' && ! $this->looksLikeUrl($url)) {
            $language = $this->languages->resolve($httpRequest, $request->authentication);

            return $this->responses->error(
                $request,
                $this->translator->translate('SitesManager_ExceptionInvalidUrl', $language, [$url]),
                400,
            );
        }

        return $this->responses->row(
            $request,
            $this->checks->initiate($siteId, $url === '' ? $mainUrl : $url),
        );
    }

    private function looksLikeUrl(string $url): bool
    {
        return preg_match('~^(([[:alpha:]][[:alnum:]+.-]*)?:)?//(.+)$~D', $url, $matches) === 1
            && ! preg_match('/^(javascript:|vbscript:|data:)/i', $matches[1]);
    }
}
