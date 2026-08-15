<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Marketplace\MarketplaceException;
use App\Matomo\Marketplace\MarketplaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class MarketplaceApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MarketplaceService $marketplace,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->marketplace !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Marketplace API handler does not support this method.');
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);
        $isSuperUser = $this->authorizer->hasSuperUserAccess($request->authentication);

        if ($request->method === 'Marketplace.requestTrial') {
            if (in_array($login, [null, '', 'anonymous'], true)) {
                return $this->responses->error($request, 'Authentication is required.', 401);
            }

            if ($isSuperUser) {
                return $this->responses->error($request, 'Cannot request trial as a super user', 403);
            }
        } elseif (! $isSuperUser) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $parameters = $request->marketplace
            ?? throw new LogicException('The Marketplace API parameters are missing.');

        try {
            match ($request->method) {
                'Marketplace.createAccount' => $this->marketplace->createAccount($parameters->email ?? ''),
                'Marketplace.deleteLicenseKey' => $this->marketplace->deleteLicenseKey(),
                'Marketplace.requestTrial' => $this->marketplace->requestTrial(
                    $parameters->pluginName ?? '',
                    $login ?? '',
                ),
                'Marketplace.startFreeTrial' => $this->marketplace->startFreeTrial($parameters->pluginName ?? ''),
                'Marketplace.saveLicenseKey' => $this->marketplace->saveLicenseKey($parameters->licenseKey ?? ''),
                default => throw new LogicException('The Marketplace API method is not implemented.'),
            };
        } catch (MarketplaceException $marketplaceException) {
            return $this->responses->error(
                $request,
                $marketplaceException->getMessage(),
                $marketplaceException->responseStatus,
            );
        }

        return $this->responses->scalar($request, true);
    }
}
