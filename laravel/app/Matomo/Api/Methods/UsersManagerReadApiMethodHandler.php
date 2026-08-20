<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Users\UserDirectoryRepository;
use App\Matomo\Users\UserPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerReadApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private UserDirectoryRepository $users,
        private UserPresenter $presenter,
        private PluginState $plugins,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerRead !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager read API handler does not support this request.');
        }

        $parameters = $request->usersManagerRead
            ?? throw new LogicException('The UsersManager read parameters were not parsed.');
        $currentLogin = $this->authorizer->authenticatedLogin($request->authentication);

        return match ($request->method) {
            'UsersManager.getUsers', 'UsersManager.getUsersLogin' => $this->listUsers(
                $request,
                $currentLogin,
                $parameters->userLogins,
            ),
            'UsersManager.getUser' => $this->oneUser($request, $currentLogin, $parameters->userLogin),
            'UsersManager.getUserByEmail' => $this->userByEmail(
                $request,
                $currentLogin,
                $parameters->userEmail,
            ),
            'UsersManager.getUsersHavingSuperUserAccess' => $this->superusers($request, $currentLogin),
            default => throw new LogicException('The UsersManager read method is not implemented.'),
        };
    }

    /** @param list<string> $requestedLogins */
    private function listUsers(ApiRequest $request, ?string $currentLogin, array $requestedLogins): Response
    {
        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have admin access to at least one website.',
                401,
            );
        }

        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $rows = $this->users->users($requestedLogins);
        if (! $superuser) {
            $login = $currentLogin ?? 'anonymous';
            $allowed = $this->users->visibleLogins(
                $login,
                $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
            );
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['login'] ?? null, $allowed, true)
                    && ($request->method === 'UsersManager.getUsersLogin'
                        || empty($row['invite_token'])
                        || ($row['invited_by'] ?? null) === $login),
            ));
        }

        if ($request->method === 'UsersManager.getUsersLogin') {
            return $this->responses->values(
                $request,
                array_values(array_filter(array_column($rows, 'login'), is_string(...))),
            );
        }

        return $this->responses->rows(
            $request,
            $this->present($rows, $currentLogin ?? 'anonymous', $superuser),
        );
    }

    private function oneUser(ApiRequest $request, ?string $currentLogin, ?string $requestedLogin): Response
    {
        $login = $requestedLogin ?? throw new LogicException('The user login was not parsed.');
        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $ownLogin = ($currentLogin !== null && $currentLogin === $login)
            || ($currentLogin === null && $login === 'anonymous');
        if (! $superuser && ! $ownLogin) {
            return $this->responses->error($request, 'You can only access your own user account.', 401);
        }

        $user = $this->users->user($login);
        if ($user === null) {
            return $this->responses->error($request, 'User does not exist: '.$login, 404);
        }

        return $this->responses->structured(
            $request,
            $this->presenter->present(
                $user,
                $currentLogin ?? 'anonymous',
                $superuser,
                $this->plugins->isActivated('TwoFactorAuth'),
            ),
        );
    }

    private function userByEmail(ApiRequest $request, ?string $currentLogin, ?string $email): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->superuserError($request);
        }

        $requestedEmail = $email ?? throw new LogicException('The user email was not parsed.');
        $user = $this->users->userByEmail($requestedEmail);
        if ($user === null) {
            return $this->responses->error($request, 'User does not exist: '.$requestedEmail, 404);
        }

        return $this->responses->structured(
            $request,
            $this->presenter->present(
                $user,
                $currentLogin ?? 'anonymous',
                true,
                $this->plugins->isActivated('TwoFactorAuth'),
            ),
        );
    }

    private function superusers(ApiRequest $request, ?string $currentLogin): Response
    {
        if ($currentLogin === null || strcasecmp($currentLogin, 'anonymous') === 0) {
            return $this->responses->error($request, 'Anonymous users cannot access this resource.', 401);
        }

        return $this->responses->rows(
            $request,
            $this->present(
                $this->users->superusers(),
                $currentLogin,
                $this->authorizer->hasSuperUserAccess($request->authentication),
            ),
        );
    }

    /** @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    private function present(array $users, string $currentLogin, bool $superuser): array
    {
        $twoFactor = $this->plugins->isActivated('TwoFactorAuth');

        return array_map(
            fn (array $user): array => $this->presenter->present(
                $user,
                $currentLogin,
                $superuser,
                $twoFactor,
            ),
            $users,
        );
    }

    private function superuserError(ApiRequest $request): Response
    {
        return $this->responses->error(
            $request,
            "You can't access this resource as it requires a 'superuser' access.",
            401,
        );
    }
}
