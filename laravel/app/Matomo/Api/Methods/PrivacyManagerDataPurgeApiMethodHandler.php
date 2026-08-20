<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Privacy\DataPurger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerDataPurgeApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private PasswordConfirmationVerifier $passwords,
        private DataPurger $purger,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyPurgeExecution !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy data-purge handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        $parameters = $request->privacyPurgeExecution
            ?? throw new LogicException('The data-purge parameters were not parsed.');
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        if ($login === null || $parameters->passwordConfirmation === null
            || ! $this->passwords->isCorrect($login, $parameters->passwordConfirmation)) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        $this->purger->purge();

        return $this->responses->scalar($request, true);
    }
}
