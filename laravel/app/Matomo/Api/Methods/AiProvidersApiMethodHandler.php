<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\AiProviders\AiProviderConfigurationException;
use App\Matomo\AiProviders\AiProviderConnectionException;
use App\Matomo\AiProviders\AiProviderConnectionTester;
use App\Matomo\AiProviders\AiProviderSettingsManager;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class AiProvidersApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private AiProviderSettingsManager $settings,
        private AiProviderConnectionTester $connections,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isAiProvidersRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The AIProviders API handler does not support this method.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $parameters = $request->aiProvider
            ?? throw new LogicException('The AIProviders API parameters are missing.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        try {
            $result = match ($request->method) {
                'AIProviders.getSettings' => $this->settings->settings(),
                'AIProviders.saveSettings' => $this->settings->save(
                    $parameters->defaultProviderId,
                    $parameters->defaultCapabilityLevel,
                    $parameters->providerConfigurations(),
                ),
                'AIProviders.testConnection' => $this->testConnection(
                    $parameters->providerId,
                    $parameters->providerConfiguration(),
                ),
                'AIProviders.disconnectProvider' => $this->settings->disconnect($parameters->providerId),
                default => throw new LogicException('The AIProviders API method is not implemented.'),
            };
        } catch (AiProviderConfigurationException $exception) {
            $message = $exception->translationKey === null
                ? $exception->getMessage()
                : $this->translator->translate(
                    $exception->translationKey,
                    $language,
                    $exception->translationArguments,
                );

            return $this->responses->error($request, $message, 400);
        } catch (AiProviderConnectionException $exception) {
            return $this->responses->error(
                $request,
                $exception->getMessage(),
                $exception->responseStatus,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->responses->error($request, $exception->getMessage(), 400);
        }

        return $this->responses->structured($request, $result);
    }

    /** @return array{providerId: string, providerName: string, models: list<string>} */
    private function testConnection(
        string $providerId,
        #[\SensitiveParameter]
        string $configuration,
    ): array {
        $target = $this->settings->connectionTarget($providerId, $configuration);

        return [
            'providerId' => $target->provider->id,
            'providerName' => $target->provider->name,
            'models' => $this->connections->test($target->provider, $target->configuration),
        ];
    }
}
