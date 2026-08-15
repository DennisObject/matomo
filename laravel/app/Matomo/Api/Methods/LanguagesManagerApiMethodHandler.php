<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageCatalog;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Localization\MutableLanguagePreferenceRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class LanguagesManagerApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageCatalog $catalog,
        private MutableLanguagePreferenceRepository $preferences,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isLanguagesManagerRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The LanguagesManager API handler does not support this request.');
        }

        $parameters = $request->languagesManager
            ?? throw new LogicException('The LanguagesManager API parameters were not parsed.');

        return match ($request->method) {
            'LanguagesManager.isLanguageAvailable' => $this->responses->scalar(
                $request,
                $this->catalog->isAvailable($parameters->languageCode, $parameters->ignoreConfig),
            ),
            'LanguagesManager.getAvailableLanguages' => $this->responses->values(
                $request,
                $this->catalog->available($parameters->ignoreConfig),
            ),
            'LanguagesManager.getAvailableLanguagesInfo' => $this->responses->rows(
                $request,
                $this->catalog->information(
                    $parameters->excludeNonCorePlugins,
                    $parameters->ignoreConfig,
                ),
            ),
            'LanguagesManager.getAvailableLanguageNames' => $this->responses->rows(
                $request,
                $this->catalog->names($parameters->ignoreConfig),
            ),
            'LanguagesManager.getTranslationsForLanguage' => $this->translations(
                $request,
                $parameters->languageCode,
            ),
            'LanguagesManager.getLanguageForUser' => $this->languageForUser(
                $request,
                $httpRequest,
                $parameters->login,
            ),
            'LanguagesManager.setLanguageForUser' => $this->setLanguageForUser(
                $request,
                $httpRequest,
                $parameters->login,
                $parameters->languageCode,
            ),
            'LanguagesManager.uses12HourClockForUser' => $this->clockPreference(
                $request,
                $httpRequest,
                $parameters->login,
            ),
            'LanguagesManager.set12HourClockForUser' => $this->setClockPreference(
                $request,
                $httpRequest,
                $parameters->login,
                $parameters->use12HourClock,
            ),
            default => throw new LogicException('The LanguagesManager API method is not implemented.'),
        };
    }

    private function translations(ApiRequest $request, string $languageCode): Response
    {
        $translations = $this->catalog->translations($languageCode);

        return $translations === null
            ? $this->responses->scalar($request, false)
            : $this->responses->rows($request, $translations);
    }

    private function languageForUser(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
    ): Response {
        if (strtolower($login) === 'anonymous') {
            return $this->responses->scalar($request, false);
        }

        $denied = $this->accessDenied($request, $httpRequest, $login);

        return $denied ?? $this->responses->scalar(
            $request,
            $this->preferences->forLogin($login) ?? false,
        );
    }

    private function setLanguageForUser(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
        string $languageCode,
    ): Response {
        $denied = $this->accessDenied($request, $httpRequest, $login);

        if ($denied !== null) {
            return $denied;
        }

        $loginRequired = $this->loginRequired($request, $httpRequest);

        if ($loginRequired !== null) {
            return $loginRequired;
        }

        return $this->responses->scalar(
            $request,
            $this->catalog->isAvailable($languageCode)
                && $this->preferences->setLanguage($login, $languageCode),
        );
    }

    private function clockPreference(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
    ): Response {
        if (strtolower($login) === 'anonymous') {
            return $this->responses->scalar($request, false);
        }

        $denied = $this->accessDenied($request, $httpRequest, $login);

        return $denied ?? $this->responses->scalar(
            $request,
            $this->preferences->uses12HourClock($login),
        );
    }

    private function setClockPreference(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
        bool $use12HourClock,
    ): Response {
        if (strtolower($login) === 'anonymous') {
            return $this->responses->scalar($request, false);
        }

        $denied = $this->accessDenied($request, $httpRequest, $login);

        return $denied ?? $this->responses->scalar(
            $request,
            $this->preferences->set12HourClock($login, $use12HourClock),
        );
    }

    private function accessDenied(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
    ): ?Response {
        if ($this->authorizer->hasSuperUserAccess($request->authentication)
            || $this->authorizer->authenticatedLogin($request->authentication) === $login) {
            return null;
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);

        return $this->responses->error(
            $request,
            $this->translator->translate(
                'General_ExceptionCheckUserHasSuperUserAccessOrIsTheUser',
                $language,
                [$login],
            ),
            401,
        );
    }

    private function loginRequired(ApiRequest $request, Request $httpRequest): ?Response
    {
        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if (! in_array($login, [null, '', 'anonymous'], true)) {
            return null;
        }

        return $this->responses->error(
            $request,
            $this->translator->translate(
                'General_YouMustBeLoggedIn',
                $this->languages->resolve($httpRequest, $request->authentication),
            ),
            401,
        );
    }
}
