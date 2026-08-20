<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Users\AccessMetadataProvider;
use App\Matomo\Users\UserDirectoryRepository;
use App\Matomo\Users\UserPresenter;
use App\Matomo\Users\UserSiteAccessRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerSiteAccessApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private UserSiteAccessRepository $access,
        private UserDirectoryRepository $users,
        private SiteRepository $sites,
        private AccessMetadataProvider $metadata,
        private UserPresenter $presenter,
        private PluginState $plugins,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerSiteAccess !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager site-access API handler does not support this request.');
        }

        $parameters = $request->usersManagerSiteAccess
            ?? throw new LogicException('The UsersManager site-access parameters were not parsed.');

        return match ($request->method) {
            'UsersManager.getUsersSitesFromAccess' => $this->sitesByLogin($request, $parameters->access),
            'UsersManager.getUsersAccessFromSite' => $this->accessByLogin($request, $parameters->siteId),
            'UsersManager.getUsersWithSiteAccess' => $this->usersWithAccess(
                $request,
                $parameters->siteId,
                $parameters->access,
            ),
            'UsersManager.getSitesAccessFromUser' => $this->accessForUser($request, $parameters->userLogin),
            'UsersManager.getSitesAccessForUser' => $this->filteredAccessForUser(
                $request,
                $parameters->userLogin,
                $parameters->limit,
                $parameters->offset,
                $parameters->search,
                $parameters->accessFilter,
            ),
            default => throw new LogicException('The UsersManager site-access method is not implemented.'),
        };
    }

    private function sitesByLogin(ApiRequest $request, ?string $access): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->superuserError($request);
        }

        $entry = $access ?? throw new LogicException('The access entry was not parsed.');
        if (! $this->validAccess($entry)) {
            return $this->invalidAccess($request, $entry);
        }

        return $this->responses->structured($request, $this->access->sitesByLogin($entry));
    }

    private function accessByLogin(ApiRequest $request, ?int $siteId): Response
    {
        $idSite = $siteId ?? throw new LogicException('The site ID was not parsed.');
        if (! $this->siteAdmin($request, $idSite)) {
            return $this->siteAdminError($request, $idSite);
        }

        return $this->responses->structured($request, $this->access->accessByLogin($idSite));
    }

    private function usersWithAccess(ApiRequest $request, ?int $siteId, ?string $access): Response
    {
        $idSite = $siteId ?? throw new LogicException('The site ID was not parsed.');
        if (! $this->siteAdmin($request, $idSite)) {
            return $this->siteAdminError($request, $idSite);
        }

        $entry = $access ?? throw new LogicException('The access entry was not parsed.');
        if (! $this->validAccess($entry)) {
            return $this->invalidAccess($request, $entry);
        }

        $logins = $this->access->logins($idSite, $entry);
        if ($logins === []) {
            return $this->responses->rows($request, []);
        }

        $current = $this->authorizer->authenticatedLogin($request->authentication) ?? 'anonymous';
        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $users = array_values(array_filter(
            $this->users->users($logins),
            static fn (array $user): bool => empty($user['invite_token'])
                || ($user['invited_by'] ?? null) === $current,
        ));
        $twoFactor = $this->plugins->isActivated('TwoFactorAuth');

        return $this->responses->rows($request, array_map(
            fn (array $user): array => $this->presenter->present(
                $user,
                $current,
                $superuser,
                $twoFactor,
            ),
            $users,
        ));
    }

    private function accessForUser(ApiRequest $request, ?string $login): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->superuserError($request);
        }

        $userLogin = $login ?? throw new LogicException('The user login was not parsed.');
        $user = $this->users->user($userLogin);
        if ($user === null) {
            return $this->responses->error($request, 'User does not exist: '.$userLogin, 404);
        }

        if (! empty($user['superuser_access'])) {
            return $this->responses->rows($request, array_map(
                static fn (int $siteId): array => ['site' => $siteId, 'access' => 'admin'],
                $this->sites->allIds(),
            ));
        }

        return $this->responses->rows($request, $this->access->forUser($userLogin));
    }

    private function filteredAccessForUser(
        ApiRequest $request,
        ?string $login,
        ?int $limit,
        int $offset,
        ?string $search,
        ?string $accessFilter,
    ): Response {
        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have admin access to at least one website.',
                401,
            );
        }

        $userLogin = $login ?? throw new LogicException('The user login was not parsed.');
        $user = $this->users->user($userLogin);
        if ($user === null) {
            return $this->responses->error($request, 'User does not exist: '.$userLogin, 404);
        }

        if (! empty($user['superuser_access'])) {
            return $this->responses->error($request, 'This method should not be used with superusers.', 400);
        }

        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $allowedSites = $superuser
            ? null
            : $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin);
        if ($allowedSites === []) {
            return $this->responses->error(
                $request,
                'The current admin user does not have access to any sites.',
                401,
            );
        }

        $result = $this->access->filteredForUser(
            $userLogin,
            $limit,
            $offset,
            $search,
            $accessFilter,
            $allowedSites,
        );
        $capabilityIds = array_column($this->metadata->capabilities(), 'id');
        $rows = array_map(static function (array $row) use ($capabilityIds): array {
            $roles = array_values(array_intersect($row['access'], ['view', 'write', 'admin']));
            $capabilities = array_values(array_intersect($row['access'], $capabilityIds));

            return [
                'idsite' => $row['idsite'],
                'site_name' => $row['site_name'],
                'role' => $roles[0] ?? 'noaccess',
                'capabilities' => $capabilities,
            ];
        }, $result['rows']);

        $response = $this->responses->rows($request, $rows)
            ->header('X-Matomo-Total-Results', (string) $result['total']);
        if ($result['hasSome']) {
            $response->header('X-Matomo-Has-Some', '1');
        }

        return $response;
    }

    private function siteAdmin(ApiRequest $request, int $siteId): bool
    {
        return $this->authorizer->hasSuperUserAccess($request->authentication)
            || in_array(
                $siteId,
                $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
                true,
            );
    }

    private function validAccess(string $access): bool
    {
        $capabilities = array_column($this->metadata->capabilities(), 'id');

        return in_array($access, ['view', 'write', 'admin', ...$capabilities], true);
    }

    private function invalidAccess(ApiRequest $request, string $access): Response
    {
        return $this->responses->error($request, 'Invalid access value: '.$access, 400);
    }

    private function siteAdminError(ApiRequest $request, int $siteId): Response
    {
        return $this->responses->error(
            $request,
            "You can't access this resource as it requires 'admin' access for the website id = {$siteId}.",
            401,
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
