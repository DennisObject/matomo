<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\MobileMessaging\MobileMessagingException;
use App\Matomo\MobileMessaging\MobileMessagingManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class MobileMessagingApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MobileMessagingManager $mobileMessaging,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->mobileMessaging !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->mobileMessaging
            ?? throw new LogicException('The MobileMessaging API parameters are missing.');
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        $authenticated = ! in_array($login, [null, '', 'anonymous'], true);
        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $someView = $this->authorizer->hasSomeViewAccess($request->authentication);
        $credentialAccess = $this->mobileMessaging->delegatedManagement() ? $authenticated : $superUser;

        if (in_array($request->method, [
            'MobileMessaging.areSMSAPICredentialProvided',
            'MobileMessaging.getDelegatedManagement',
        ], true) && ! $someView) {
            return $this->unauthorized($request);
        }

        if (in_array($request->method, [
            'MobileMessaging.getSMSProvider', 'MobileMessaging.setSMSAPICredential',
            'MobileMessaging.getCreditLeft', 'MobileMessaging.deleteSMSAPICredential',
        ], true) && ! $credentialAccess) {
            return $this->unauthorized($request);
        }

        if ($request->method === 'MobileMessaging.setDelegatedManagement' && ! $superUser) {
            return $this->unauthorized($request);
        }

        if (in_array($request->method, [
            'MobileMessaging.addPhoneNumber', 'MobileMessaging.resendVerificationCode',
            'MobileMessaging.getPhoneNumbers', 'MobileMessaging.removePhoneNumber',
            'MobileMessaging.validatePhoneNumber',
        ], true) && ! $authenticated) {
            return $this->unauthorized($request);
        }

        $effectiveLogin = $login ?? '';
        try {
            $result = match ($request->method) {
                'MobileMessaging.areSMSAPICredentialProvided' => $this->mobileMessaging->credentialsProvided($effectiveLogin),
                'MobileMessaging.getSMSProvider' => $this->mobileMessaging->provider($effectiveLogin) ?? '',
                'MobileMessaging.setSMSAPICredential' => $this->setCredentials($effectiveLogin, $parameters->provider ?? '', $parameters->credentials),
                'MobileMessaging.addPhoneNumber' => $this->addPhoneNumber($effectiveLogin, $parameters->phoneNumber ?? ''),
                'MobileMessaging.resendVerificationCode' => $this->resendCode($effectiveLogin, $parameters->phoneNumber ?? ''),
                'MobileMessaging.getCreditLeft' => $this->mobileMessaging->credit($effectiveLogin),
                'MobileMessaging.getPhoneNumbers' => $this->mobileMessaging->phoneNumbers($effectiveLogin),
                'MobileMessaging.removePhoneNumber' => $this->removePhoneNumber($effectiveLogin, $parameters->phoneNumber ?? ''),
                'MobileMessaging.validatePhoneNumber' => $this->mobileMessaging->validatePhoneNumber(
                    $effectiveLogin, $parameters->phoneNumber ?? '', $parameters->verificationCode ?? '',
                ),
                'MobileMessaging.deleteSMSAPICredential' => $this->deleteCredentials($effectiveLogin),
                'MobileMessaging.setDelegatedManagement' => $this->setDelegated($parameters->delegatedManagement ?? false),
                'MobileMessaging.getDelegatedManagement' => $this->mobileMessaging->delegatedManagement(),
                default => throw new LogicException('The MobileMessaging API method is not implemented.'),
            };
        } catch (MobileMessagingException $mobileMessagingException) {
            return $this->responses->error($request, $mobileMessagingException->getMessage(), 400);
        }

        return is_array($result)
            ? $this->responses->structured($request, $result)
            : $this->responses->scalar($request, $result);
    }

    /** @param array<string, string|int|null> $credentials */
    private function setCredentials(string $login, string $provider, #[\SensitiveParameter] array $credentials): bool
    {
        $this->mobileMessaging->setCredentials($login, $provider, $credentials);

        return true;
    }

    private function addPhoneNumber(string $login, string $phoneNumber): bool
    {
        $this->mobileMessaging->addPhoneNumber($login, $phoneNumber);

        return true;
    }

    private function resendCode(string $login, string $phoneNumber): bool
    {
        $this->mobileMessaging->resendVerificationCode($login, $phoneNumber);

        return true;
    }

    private function removePhoneNumber(string $login, string $phoneNumber): bool
    {
        $this->mobileMessaging->removePhoneNumber($login, $phoneNumber);

        return true;
    }

    private function deleteCredentials(string $login): bool
    {
        $this->mobileMessaging->deleteCredentials($login);

        return true;
    }

    private function setDelegated(bool $enabled): bool
    {
        $this->mobileMessaging->setDelegatedManagement($enabled);

        return true;
    }

    private function unauthorized(ApiRequest $request): Response
    {
        return $this->responses->error($request, 'You do not have permission to access this resource.', 401);
    }
}
