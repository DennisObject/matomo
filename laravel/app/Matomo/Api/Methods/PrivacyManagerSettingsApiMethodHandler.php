<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Privacy\DeletionBatchLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerSettingsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableOptionRepository $options,
        private PasswordConfirmationVerifier $passwords,
        private TrackerCacheInvalidator $trackerCache,
        private DeletionBatchLimits $deletionBatchLimits,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return in_array($request->method, [
            'PrivacyManager.activateDoNotTrack',
            'PrivacyManager.deactivateDoNotTrack',
        ], true) || $request->privacyPurgeSettings !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy settings handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        if (in_array($request->method, [
            'PrivacyManager.activateDoNotTrack',
            'PrivacyManager.deactivateDoNotTrack',
        ], true)) {
            $enabled = $request->method === 'PrivacyManager.activateDoNotTrack';
            $this->options->set('PrivacyManager.doNotTrackEnabled', $enabled ? '1' : '0');
            $this->trackerCache->clearGeneral();

            return $this->responses->scalar($request, true);
        }

        $parameters = $request->privacyPurgeSettings
            ?? throw new LogicException('The privacy purge parameters were not parsed.');
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        if ($login === null || $parameters->passwordConfirmation === null
            || ! $this->passwords->isCorrect($login, $parameters->passwordConfirmation)) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        $values = $parameters->values;
        if ($request->method === 'PrivacyManager.setDeleteReportsSettings') {
            $values['delete_logs_max_rows_per_query'] = $this->deletionBatchLimits->logs();
            $values['delete_logs_unused_actions_max_rows_per_query'] =
                $this->deletionBatchLimits->unusedActions();
        }

        foreach ($values as $name => $value) {
            $this->options->set($name, (string) $value);
        }

        $this->trackerCache->clearGeneral();

        return $this->responses->scalar($request, true);
    }
}
