<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Users\AccessMetadataProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerAccessApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private AccessMetadataProvider $metadata,
        private LanguageResolver $languages,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return in_array($request->method, [
            'UsersManager.getAvailableRoles',
            'UsersManager.getAvailableCapabilities',
        ], true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager access API handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have admin access to at least one website.',
                401,
            );
        }

        $rows = $request->method === 'UsersManager.getAvailableRoles'
            ? $this->metadata->roles($this->languages->resolve($httpRequest, $request->authentication))
            : $this->metadata->capabilities();

        return $this->responses->rows($request, $rows);
    }
}
