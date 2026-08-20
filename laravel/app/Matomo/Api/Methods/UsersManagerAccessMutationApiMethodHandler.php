<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Users\AccessMetadataProvider;
use App\Matomo\Users\AnonymousAccessNotifier;
use App\Matomo\Users\Events\UserSiteAccessRemoved;
use App\Matomo\Users\MutableUserSiteAccessRepository;
use App\Matomo\Users\UserDirectoryRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerAccessMutationApiMethodHandler implements ApiMethodHandler
{
    /** @var list<string> */
    private const array ROLES = ['view', 'write', 'admin'];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableUserSiteAccessRepository $access,
        private UserDirectoryRepository $users,
        private AccessMetadataProvider $metadata,
        private SiteRuntimeSettings $runtime,
        private PasswordConfirmationVerifier $passwords,
        private AnonymousAccessNotifier $anonymousNotifier,
        private TrackerCacheInvalidator $trackerCache,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerAccessMutation !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager access mutation handler does not support this request.');
        }

        if (! $this->runtime->administrationEnabled()) {
            return $this->responses->error($request, 'Users administration is disabled.', 403);
        }

        $parameters = $request->usersManagerAccessMutation
            ?? throw new LogicException('The UsersManager access mutation parameters were not parsed.');
        $siteIds = $this->siteIds($request, $parameters->siteIds);
        if ($siteIds instanceof Response) {
            return $siteIds;
        }

        $user = $this->users->user($parameters->userLogin);
        if ($user === null || ! is_string($user['login'] ?? null)) {
            return $this->responses->error(
                $request,
                'User does not exist: '.$parameters->userLogin,
                404,
            );
        }

        $login = $user['login'];
        $capabilities = $this->capabilities();

        return match ($request->method) {
            'UsersManager.setUserAccess' => $this->replace(
                $request,
                $login,
                $siteIds,
                $parameters->entries,
                $parameters->entriesWereArray,
                $parameters->passwordConfirmation,
                $capabilities,
            ),
            'UsersManager.addCapabilities' => $this->addCapabilities(
                $request,
                $login,
                $siteIds,
                $parameters->entries,
                $capabilities,
            ),
            'UsersManager.removeCapabilities' => $this->removeCapabilities(
                $request,
                $login,
                $siteIds,
                $parameters->entries,
                $capabilities,
            ),
            default => throw new LogicException('The UsersManager access mutation method is not implemented.'),
        };
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $entries
     * @param  array<string, list<string>>  $capabilities
     */
    private function replace(
        ApiRequest $request,
        string $login,
        array $siteIds,
        array $entries,
        bool $entriesWereArray,
        ?string $passwordConfirmation,
        array $capabilities,
    ): Response {
        $noAccess = ! $entriesWereArray && $entries === ['noaccess'];
        if ($entriesWereArray && strcasecmp($login, 'anonymous') === 0) {
            return $this->anonymousAccessError($request);
        }

        $roles = array_values(array_intersect($entries, self::ROLES));
        $capabilityIds = array_keys($capabilities);
        $requestedCapabilities = array_values(array_intersect($entries, $capabilityIds));
        if (! $noAccess && count($roles) !== 1) {
            return $this->responses->error(
                $request,
                count($roles) === 0 ? 'A role must be set.' : 'Only one role can be set.',
                400,
            );
        }

        if (count($entries) !== count($roles) + count($requestedCapabilities) + ($noAccess ? 1 : 0)) {
            return $this->responses->error($request, 'One or more access values are invalid.', 400);
        }

        if (! $entriesWereArray && ! $noAccess && count($requestedCapabilities) > 0) {
            return $this->responses->error($request, 'A single access value must be a role.', 400);
        }

        if (strcasecmp($login, 'anonymous') === 0
            && ! $noAccess && $roles !== ['view']) {
            return $this->anonymousAccessError($request);
        }

        if (! $noAccess) {
            $requestedCapabilities = array_values(array_filter(
                $requestedCapabilities,
                static fn (string $capability): bool => ! in_array(
                    $roles[0],
                    $capabilities[$capability],
                    true,
                ),
            ));
        }

        $sensitiveGrant = (strcasecmp($login, 'anonymous') === 0 && $roles === ['view'])
            || $roles === ['admin'];
        if ($sensitiveGrant && $request->authentication->sessionId !== null) {
            $current = $this->authorizer->authenticatedLogin($request->authentication);
            if ($current === null || $passwordConfirmation === null
                || ! $this->passwords->isCorrect($current, $passwordConfirmation)) {
                return $this->responses->error($request, 'The password confirmation is invalid.', 403);
            }
        }

        $result = $this->access->replace(
            $login,
            $siteIds,
            $noAccess ? null : $roles[0],
            $requestedCapabilities,
        );
        if ($result === 'superuser') {
            return $this->responses->error($request, 'Superuser access cannot be changed per site.', 400);
        }

        if ($noAccess) {
            $this->events->dispatch(new UserSiteAccessRemoved($login, $siteIds));
        } elseif (strcasecmp($login, 'anonymous') === 0 && $roles === ['view']) {
            $this->anonymousNotifier->notify($siteIds);
        }

        return $this->mutationSuccess($request);
    }

    /** @param list<int> $siteIds
     * @param  list<string>  $requested
     * @param  array<string, list<string>>  $capabilities
     */
    private function addCapabilities(
        ApiRequest $request,
        string $login,
        array $siteIds,
        array $requested,
        array $capabilities,
    ): Response {
        if (strcasecmp($login, 'anonymous') === 0) {
            return $this->responses->error($request, 'Capabilities cannot be assigned to anonymous.', 400);
        }

        if (! $this->validCapabilities($requested, $capabilities)) {
            return $this->responses->error($request, 'One or more capabilities are invalid.', 400);
        }

        $includedInRoles = array_intersect_key($capabilities, array_flip($requested));
        $result = $this->access->addCapabilities($login, $siteIds, $includedInRoles);
        if ($result === 'superuser') {
            return $this->responses->error($request, 'Capabilities cannot be assigned to a superuser.', 400);
        }

        if (is_int($result)) {
            return $this->responses->error(
                $request,
                "User {$login} has no role for website id = {$result}.",
                400,
            );
        }

        return $this->mutationSuccess($request);
    }

    /** @param list<int> $siteIds
     * @param  list<string>  $requested
     * @param  array<string, list<string>>  $capabilities
     */
    private function removeCapabilities(
        ApiRequest $request,
        string $login,
        array $siteIds,
        array $requested,
        array $capabilities,
    ): Response {
        if (! $this->validCapabilities($requested, $capabilities)) {
            return $this->responses->error($request, 'One or more capabilities are invalid.', 400);
        }

        $this->access->removeCapabilities($login, $siteIds, $requested);

        return $this->mutationSuccess($request);
    }

    /** @param list<string> $requested
     * @param  array<string, list<string>>  $capabilities
     */
    private function validCapabilities(array $requested, array $capabilities): bool
    {
        return $requested !== [] && array_diff($requested, array_keys($capabilities)) === [];
    }

    /** @return array<string, list<string>> */
    private function capabilities(): array
    {
        $result = [];
        foreach ($this->metadata->capabilities() as $capability) {
            $result[$capability['id']] = $capability['includedInRoles'];
        }

        return $result;
    }

    /** @param list<string> $requested
     * @return list<int>|Response
     */
    private function siteIds(ApiRequest $request, array $requested): array|Response
    {
        $adminSites = $this->authorizer->siteIdsWithRole(
            $request->authentication,
            SiteAccessRole::Admin,
        );
        $ids = $requested === ['all']
            ? $adminSites
            : array_values(array_unique(array_map(
                static fn (string $siteId): int => ctype_digit($siteId) ? (int) $siteId : 0,
                $requested,
            )));
        if ($ids === [] || in_array(0, $ids, true) || array_diff($ids, $adminSites) !== []) {
            return $this->responses->error(
                $request,
                'You do not have admin access to every requested website.',
                401,
            );
        }

        return $ids;
    }

    private function mutationSuccess(ApiRequest $request): Response
    {
        $this->trackerCache->clearGeneral();

        return $this->responses->success($request);
    }

    private function anonymousAccessError(ApiRequest $request): Response
    {
        return $this->responses->error(
            $request,
            'Anonymous access can only be set to noaccess or view.',
            400,
        );
    }
}
