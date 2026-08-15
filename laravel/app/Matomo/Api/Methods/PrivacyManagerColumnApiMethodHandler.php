<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Privacy\AnonymizableColumnProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerColumnApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private AnonymizableColumnProvider $columns,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return in_array($request->method, [
            'PrivacyManager.getAvailableVisitColumnsToAnonymize',
            'PrivacyManager.getAvailableLinkVisitActionColumnsToAnonymize',
        ], true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy column handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        $table = $request->method === 'PrivacyManager.getAvailableVisitColumnsToAnonymize'
            ? 'log_visit' : 'log_link_visit_action';

        return $this->responses->rows($request, $this->columns->forTable($table));
    }
}
