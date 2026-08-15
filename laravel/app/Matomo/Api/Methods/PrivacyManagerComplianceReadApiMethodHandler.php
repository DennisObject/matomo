<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Privacy\ComplianceStatusProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerComplianceReadApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private ComplianceStatusProvider $status,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyComplianceRead !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy compliance read handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        $parameters = $request->privacyComplianceRead
            ?? throw new LogicException('The privacy compliance read parameters were not parsed.');
        if ($parameters->policy !== 'cnil_v1') {
            return $this->responses->error($request, 'Invalid compliance type.', 400);
        }

        $idSite = $this->siteId($parameters->site);
        if ($idSite === false) {
            return $this->responses->error($request, 'The idSite must be a positive integer or all.', 400);
        }

        return $this->responses->structured($request, $this->status->status($idSite));
    }

    private function siteId(string $site): int|null|false
    {
        if ($site === 'all') {
            return null;
        }

        return preg_match('/^[1-9][0-9]*$/D', $site) === 1 ? (int) $site : false;
    }
}
