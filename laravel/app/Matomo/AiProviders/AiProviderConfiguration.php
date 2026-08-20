<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

final readonly class AiProviderConfiguration
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $endpointUrl,
        public string $model,
        public bool $useFipsEndpoint,
    ) {}

    /**
     * @return array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool}
     */
    public function toArray(): array
    {
        return [
            'apiKey' => $this->apiKey,
            'endpointUrl' => $this->endpointUrl,
            'model' => $this->model,
            'useFipsEndpoint' => $this->useFipsEndpoint,
        ];
    }
}
