<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class OverlayApiMethodHandler implements ApiMethodHandler
{
    private const array TRANSLATIONS = [
        'oneClick' => 'Overlay_OneClick',
        'clicks' => 'Overlay_Clicks',
        'clicksFromXLinks' => 'Overlay_ClicksFromXLinks',
        'link' => 'Overlay_Link',
    ];

    public function __construct(
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isOverlayTranslationsRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Overlay API handler does not support this request.');
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $translations = [];

        foreach (self::TRANSLATIONS as $name => $key) {
            $translations[$name] = $this->translator->translate($key, $language);
        }

        return $this->responses->row($request, $translations);
    }
}
