<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Users\UserIdentityRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerIdentityApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private UserIdentityRepository $users,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerIdentity !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager identity API handler does not support this request.');
        }

        if ($request->method === 'UsersManager.hasSuperUserAccess') {
            return $this->responses->scalar(
                $request,
                $this->authorizer->hasSuperUserAccess($request->authentication),
            );
        }

        $parameters = $request->usersManagerIdentity
            ?? throw new LogicException('The UsersManager identity parameters were not parsed.');
        if ($request->method === 'UsersManager.userExists'
            && strcasecmp($parameters->userLogin ?? '', 'anonymous') === 0) {
            return $this->responses->scalar($request, true);
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);
        if ($login === null || strcasecmp($login, 'anonymous') === 0) {
            return $this->responses->error($request, 'Anonymous users cannot access this resource.', 401);
        }

        if ($request->method === 'UsersManager.getUserLoginFromUserEmail') {
            if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    'You must have admin access to at least one website.',
                    401,
                );
            }

            $email = $parameters->userEmail ?? throw new LogicException('The user email was not parsed.');
            $matchedLogin = $this->users->loginForEmail($email);

            return $matchedLogin === null
                ? $this->responses->error($request, 'User with email '.$email.' does not exist.', 404)
                : $this->responses->scalar($request, $matchedLogin);
        }

        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        if ($request->method === 'UsersManager.userExists') {
            $requested = $parameters->userLogin ?? throw new LogicException('The user login was not parsed.');

            return $this->responses->scalar(
                $request,
                strcasecmp($login, $requested) === 0 || $this->users->loginExists($requested),
            );
        }

        $email = $parameters->userEmail ?? throw new LogicException('The user email was not parsed.');

        return $this->responses->scalar($request, $this->users->emailExists($email));
    }
}
