<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Users\AccessMetadataProvider;
use App\Matomo\Users\UserDirectoryRepository;
use App\Matomo\Users\UserPresenter;
use App\Matomo\Users\UserRoleDirectoryRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerRoleDirectoryApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private UserRoleDirectoryRepository $roles,
        private UserDirectoryRepository $users,
        private AccessMetadataProvider $metadata,
        private UserPresenter $presenter,
        private PluginState $plugins,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerRoleDirectory !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager role-directory handler does not support this request.');
        }

        $parameters = $request->usersManagerRoleDirectory
            ?? throw new LogicException('The UsersManager role-directory parameters were not parsed.');
        $current = $this->authorizer->authenticatedLogin($request->authentication);
        if ($current === null || strcasecmp($current, 'anonymous') === 0) {
            return $this->withTotal($this->responses->rows($request, []), 0);
        }

        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $adminSites = $this->authorizer->siteIdsWithRole(
            $request->authentication,
            SiteAccessRole::Admin,
        );
        if (! $superuser && ! in_array($parameters->siteId, $adminSites, true)) {
            return $this->ownUser($request, $current, $parameters->siteId, $parameters->offset);
        }

        $allowedLogins = $superuser ? null : $this->users->visibleLogins($current, $adminSites);
        if ($allowedLogins === []) {
            return $this->withTotal($this->responses->rows($request, []), 0);
        }

        $result = $this->roles->filtered(
            $parameters->siteId,
            $parameters->limit,
            $parameters->offset,
            $parameters->search,
            $parameters->access,
            $parameters->status,
            $allowedLogins,
            $current,
            $superuser,
        );

        return $this->withTotal(
            $this->responses->rows(
                $request,
                $this->present($result['rows'], $current, $superuser),
            ),
            $result['total'],
        );
    }

    private function ownUser(ApiRequest $request, string $login, int $siteId, int $offset): Response
    {
        if ($offset > 1) {
            return $this->withTotal($this->responses->rows($request, []), 1);
        }

        $user = $this->users->user($login);
        if ($user === null) {
            return $this->withTotal($this->responses->rows($request, []), 0);
        }

        $user['access'] = $this->roles->accessEntries($login, $siteId);

        return $this->withTotal(
            $this->responses->rows($request, $this->present([$user], $login, false)),
            1,
        );
    }

    /** @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    private function present(array $users, string $current, bool $superuser): array
    {
        $capabilityIds = array_column($this->metadata->capabilities(), 'id');
        $twoFactor = $this->plugins->isActivated('TwoFactorAuth');

        return array_map(function (array $user) use ($capabilityIds, $current, $superuser, $twoFactor): array {
            $isSuperuser = (int) ($user['superuser_access'] ?? 0) === 1;
            $entries = is_array($user['access'] ?? null) ? $user['access'] : [];
            $roles = array_values(array_intersect($entries, ['view', 'write', 'admin']));
            $user['superuser_access'] = $isSuperuser;
            $user['role'] = $isSuperuser ? 'superuser' : ($roles[0] ?? 'noaccess');
            $user['capabilities'] = $isSuperuser
                ? []
                : array_values(array_intersect($entries, $capabilityIds));
            unset($user['access']);

            return $this->presenter->present($user, $current, $superuser, $twoFactor);
        }, $users);
    }

    private function withTotal(Response $response, int $total): Response
    {
        return $response->header('X-Matomo-Total-Results', (string) $total);
    }
}
