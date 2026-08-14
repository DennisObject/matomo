<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use Illuminate\Http\Response;
use LogicException;
use Piwik\Version;

final readonly class VersionApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isVersionRequest() || $request->isPhpVersionRequest();
    }

    public function handle(ApiRequest $request): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The version API handler does not support this method.');
        }

        if ($request->isPhpVersionRequest()) {
            return $this->phpVersion($request);
        }

        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        return $this->responses->scalar($request, Version::VERSION);
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
