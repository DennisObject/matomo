<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Users\UserPreferenceDefaults;
use App\Matomo\Users\UserPreferenceRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerPreferenceApiMethodHandler implements ApiMethodHandler
{
    /** @var list<string> */
    private const array DEFAULT_NAMES = [
        'themeMode',
        'defaultReport',
        'defaultReportDate',
        'isLDAPUser',
        'hideSegmentDefinitionChangeMessage',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private UserPreferenceRepository $preferences,
        private MutableOptionRepository $options,
        private UserPreferenceDefaults $defaults,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerPreference !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager preference API handler does not support this request.');
        }

        $parameters = $request->usersManagerPreference
            ?? throw new LogicException('The UsersManager preference parameters were not parsed.');
        if ($request->method === 'UsersManager.getAllUsersPreferences') {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->superuserError($request);
            }

            foreach ($parameters->preferenceNames as $name) {
                if (! $this->supported($name)) {
                    return $this->unsupported($request, $name);
                }
            }

            return $this->responses->structured(
                $request,
                $this->preferences->forAllUsers($parameters->preferenceNames),
            );
        }

        $login = $parameters->userLogin
            ?? $this->authorizer->authenticatedLogin($request->authentication)
            ?? 'anonymous';
        if (! $this->canAccess($request, $login)) {
            return $this->responses->error($request, 'You can only access your own user preferences.', 401);
        }

        $canonical = $this->preferences->canonicalLogin($login);
        if ($canonical === null) {
            return $this->responses->error($request, 'User does not exist: '.$login, 404);
        }

        if (strcasecmp($canonical, 'anonymous') === 0
            && ! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->superuserError($request);
        }

        $name = $parameters->preferenceName
            ?? throw new LogicException('The preference name was not parsed.');
        if (! $this->supported($name)) {
            return $this->unsupported($request, $name);
        }

        return match ($request->method) {
            'UsersManager.setUserPreference' => $this->set($request, $canonical, $name, $parameters->preferenceValue),
            'UsersManager.getUserPreference' => $this->valueResponse(
                $request,
                $this->value($request, $canonical, $name),
            ),
            'UsersManager.initUserPreferenceWithDefault' => $this->initialize($request, $canonical, $name),
            default => throw new LogicException('The UsersManager preference method is not implemented.'),
        };
    }

    private function canAccess(ApiRequest $request, string $login): bool
    {
        $authenticated = $this->authorizer->authenticatedLogin($request->authentication);

        return ($authenticated !== null && strcasecmp($authenticated, $login) === 0)
            || ($authenticated === null && strcasecmp($login, 'anonymous') === 0)
            || $this->authorizer->hasSuperUserAccess($request->authentication);
    }

    private function set(ApiRequest $request, string $login, string $name, mixed $value): Response
    {
        $this->preferences->set($login, $name, $value);
        if ($name === 'isLDAPUser') {
            $this->options->set($login.'_'.$name, is_scalar($value) ? (string) $value : '');
        }

        return $this->responses->success($request);
    }

    private function initialize(ApiRequest $request, string $login, string $name): Response
    {
        $stored = $this->preferences->get($login, $name);
        if (! $stored['found']) {
            $default = $this->defaultValue($request, $login, $name);
            if ($default !== false) {
                $this->preferences->set($login, $name, $default);
            }
        }

        return $this->responses->success($request);
    }

    private function value(ApiRequest $request, string $login, string $name): mixed
    {
        $stored = $this->preferences->get($login, $name);

        return $stored['found'] ? $stored['value'] : $this->defaultValue($request, $login, $name);
    }

    private function defaultValue(ApiRequest $request, string $login, string $name): int|string|false
    {
        return match ($name) {
            'themeMode' => 'light',
            'defaultReport' => $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $login,
            )[0] ?? false,
            'defaultReportDate' => $this->defaults->reportDate(),
            default => false,
        };
    }

    private function supported(string $name): bool
    {
        $custom = config('matomo.user_preference_names', []);

        return ! str_contains($name, '_')
            && (in_array($name, self::DEFAULT_NAMES, true)
                || (is_array($custom) && in_array($name, $custom, true)));
    }

    private function unsupported(ApiRequest $request, string $name): Response
    {
        $message = str_contains($name, '_')
            ? 'Preference name cannot contain underscores.'
            : 'Not supported preference name: '.$name;

        return $this->responses->error($request, $message, 400);
    }

    private function valueResponse(ApiRequest $request, mixed $value): Response
    {
        if (is_array($value)) {
            return $this->responses->structured($request, $value);
        }

        return $this->responses->scalar(
            $request,
            is_bool($value) || is_float($value) || is_int($value) || is_string($value) ? $value : false,
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
