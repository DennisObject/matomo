<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\NewsletterSubscriber;
use App\Matomo\Users\UserDirectoryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Throwable;

final readonly class UsersManagerTokenNewsletterApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableUserRepository $users,
        private UserDirectoryRepository $directory,
        private PasswordConfirmationVerifier $passwords,
        private NewsletterSubscriber $newsletter,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerToken !== null
            || $request->method === 'UsersManager.newsletterSignup';
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager token/newsletter handler does not support this request.');
        }

        return $request->method === 'UsersManager.newsletterSignup'
            ? $this->newsletter($request)
            : $this->token($request);
    }

    private function token(ApiRequest $request): Response
    {
        $parameters = $request->usersManagerToken
            ?? throw new LogicException('The UsersManager token parameters were not parsed.');
        $user = $this->directory->user($parameters->login)
            ?? $this->directory->userByEmail($parameters->login);
        $login = is_string($user['login'] ?? null) ? $user['login'] : null;
        $current = $this->authorizer->authenticatedLogin($request->authentication);
        if ($current !== null && strcasecmp($current, 'anonymous') !== 0
            && ($login === null || strcasecmp($current, $login) !== 0)) {
            return $this->responses->error($request, 'You can only create a token for your own account.', 401);
        }

        if ($login === null || ! $this->passwords->isCorrect($login, $parameters->passwordConfirmation)) {
            return $this->responses->error($request, 'The current password is not correct.', 403);
        }

        if ($parameters->description === '' || mb_strlen($parameters->description) > 100) {
            return $this->responses->error($request, 'The token description must contain 1 to 100 characters.', 400);
        }

        try {
            $expiresAt = $parameters->expireDate !== null
                ? CarbonImmutable::parse($parameters->expireDate)->toDateTimeString()
                : ($parameters->expireHours > 0
                    ? CarbonImmutable::now()->addHours($parameters->expireHours)->toDateTimeString()
                    : null);
        } catch (Throwable) {
            return $this->responses->error($request, 'The token expiry date is invalid.', 400);
        }

        $result = $this->users->createToken(
            $login,
            $parameters->description,
            $expiresAt,
            $parameters->secureOnly,
        );
        if ($result['result'] !== 'created' || ! isset($result['token'])) {
            return $this->responses->error($request, 'User does not exist: '.$parameters->login, 404);
        }

        return $this->responses->scalar($request, $result['token']);
    }

    private function newsletter(ApiRequest $request): Response
    {
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        if ($login === null || strcasecmp($login, 'anonymous') === 0) {
            return $this->responses->error($request, 'Anonymous users cannot subscribe.', 401);
        }

        $user = $this->directory->user($login);
        $email = is_string($user['email'] ?? null) ? $user['email'] : null;
        if ($email === null) {
            return $this->responses->error($request, 'User does not exist: '.$login, 404);
        }

        return $this->responses->structured(
            $request,
            $this->newsletter->subscribe($login, $email) ? ['success' => true] : ['error' => true],
        );
    }
}
