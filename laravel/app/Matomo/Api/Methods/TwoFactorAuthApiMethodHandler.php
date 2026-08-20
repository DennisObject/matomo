<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Events\PasswordConfirmationRequirementChecking;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\TwoFactorAuth\TwoFactorAuthenticationResetter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class TwoFactorAuthApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private PasswordConfirmationVerifier $passwords,
        private TwoFactorAuthenticationResetter $resetter,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
        private Dispatcher $events,
        private ClientIpResolver $clientIps,
        private LoginAttemptGuard $loginAttempts,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isTwoFactorAuthRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The TwoFactorAuth API handler does not support this method.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if ($login === null || $login === '') {
            return $this->responses->error($request, 'You must be logged in.', 401);
        }

        $parameters = $request->twoFactorAuth
            ?? throw new LogicException('The TwoFactorAuth API parameters are missing.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $requirement = new PasswordConfirmationRequirementChecking($login);
        $this->events->dispatch($requirement);

        if ($requirement->required && $parameters->passwordConfirmation() === '') {
            return $this->responses->error(
                $request,
                $this->translator->translate(
                    'UsersManager_ConfirmWithReAuthentication',
                    $language,
                ),
                400,
            );
        }

        if ($requirement->required) {
            $ipAddress = $this->clientIps->resolve($httpRequest);
            $attemptStatus = $this->loginAttempts->status($ipAddress, $login);

            if ($attemptStatus !== LoginAttemptStatus::Allowed) {
                $translationKey = $attemptStatus === LoginAttemptStatus::IpBlocked
                    ? 'Login_LoginNotAllowedBecauseBlocked'
                    : 'Login_LoginNotAllowedBecauseUserLoginBlocked';

                return $this->responses->error(
                    $request,
                    $this->translator->translate($translationKey, $language),
                    403,
                );
            }

            if ($this->passwords->isCorrect($login, $parameters->passwordConfirmation())) {
                return $this->reset($request, $parameters->userLogin);
            }

            $this->loginAttempts->recordFailure($ipAddress, $login);

            return $this->responses->error(
                $request,
                $this->translator->translate('UsersManager_CurrentPasswordNotCorrect', $language),
                400,
            );
        }

        return $this->reset($request, $parameters->userLogin);
    }

    private function reset(ApiRequest $request, string $userLogin): Response
    {
        try {
            $this->resetter->reset($userLogin);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->success($request);
    }
}
