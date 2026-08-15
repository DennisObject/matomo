<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Events\PasswordConfirmationRequirementChecking;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Marketplace\PluginUpdateCounter;
use App\Matomo\Plugins\PluginSettingsException;
use App\Matomo\Plugins\PluginSettingsManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Throwable;

final readonly class CorePluginsAdminApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private PasswordConfirmationVerifier $passwords,
        private PluginSettingsManager $settings,
        private PluginUpdateCounter $updates,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->corePluginsAdmin !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->corePluginsAdmin
            ?? throw new LogicException('The CorePluginsAdmin API parameters are missing.');
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);

        try {
            return match ($request->method) {
                'CorePluginsAdmin.getSystemSettings' => $superUser
                    ? $this->responses->structured($request, $this->settings->system())
                    : $this->denied($request),
                'CorePluginsAdmin.setSystemSettings' => $superUser
                    ? $this->setSystem($request, $parameters->settingValues, $login, $parameters->passwordConfirmation)
                    : $this->denied($request),
                'CorePluginsAdmin.getUserSettings' => $this->authenticated($request, $login, false),
                'CorePluginsAdmin.setUserSettings' => $this->authenticated(
                    $request, $login, true, $parameters->settingValues,
                ),
                'CorePluginsAdmin.getNumberOfPluginUpdates' => $this->responses->scalar(
                    $request,
                    $superUser ? $this->updateCount() : 0,
                ),
                default => throw new LogicException('The CorePluginsAdmin API method is not implemented.'),
            };
        } catch (PluginSettingsException $pluginSettingsException) {
            return $this->responses->error($request, $pluginSettingsException->getMessage(), 400);
        }
    }

    /** @param array<string, mixed> $values */
    private function setSystem(ApiRequest $request, array $values, ?string $login, string $password): Response
    {
        if ($login === null || $login === '') {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        $requirement = new PasswordConfirmationRequirementChecking($login);
        $this->events->dispatch($requirement);
        if ($requirement->required && $password === '') {
            return $this->responses->error($request, 'Please confirm your password.', 400);
        }

        if ($requirement->required && ! $this->passwords->isCorrect($login, $password)) {
            return $this->responses->error($request, 'Your password is incorrect.', 400);
        }

        $this->settings->setSystem($values);

        return $this->responses->success($request);
    }

    /** @param array<string, mixed> $values */
    private function authenticated(ApiRequest $request, ?string $login, bool $write, array $values = []): Response
    {
        if (in_array($login, [null, '', 'anonymous'], true)) {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        if ($write) {
            $this->settings->setUser($login, $values);

            return $this->responses->success($request);
        }

        return $this->responses->structured($request, $this->settings->user($login));
    }

    private function denied(ApiRequest $request): Response
    {
        return $this->responses->error(
            $request,
            "You can't access this resource as it requires a 'superuser' access.",
            401,
        );
    }

    private function updateCount(): int
    {
        try {
            return $this->updates->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
