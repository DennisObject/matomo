<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Privacy\AnonymizableColumnProvider;
use App\Matomo\Privacy\RawAnonymisationScheduler;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class PrivacyManagerRawAnonymisationApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private PasswordConfirmationVerifier $passwords,
        private SiteRepository $sites,
        private AnonymizableColumnProvider $columns,
        private ReportingPeriodFactory $periods,
        private RawAnonymisationScheduler $scheduler,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyRawAnonymisation !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy raw anonymisation handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        $parameters = $request->privacyRawAnonymisation
            ?? throw new LogicException('The raw anonymisation parameters were not parsed.');
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        if ($login === null || $parameters->passwordConfirmation === null
            || ! $this->passwords->isCorrect($login, $parameters->passwordConfirmation)) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        if (! $parameters->anonymizeIp
            && ! $parameters->anonymizeLocation
            && ! $parameters->anonymizeUserId
            && $parameters->visitColumns === []
            && $parameters->actionColumns === []) {
            return $this->responses->error($request, 'Nothing is selected to be anonymized.', 400);
        }

        $idSites = $this->siteIds($parameters->sites);
        if ($idSites === false) {
            return $this->responses->error($request, 'The idSites parameter contains an invalid website.', 400);
        }

        $columnError = $this->invalidColumn($parameters->visitColumns, 'log_visit')
            ?? $this->invalidColumn($parameters->actionColumns, 'log_link_visit_action');
        if ($columnError !== null) {
            return $this->responses->error($request, $columnError, 400);
        }

        try {
            $periodName = str_contains($parameters->date, ',')
                || preg_match('/^(last|previous)[0-9]+$/D', $parameters->date) === 1
                ? 'range' : 'day';
            [$periods] = $this->periods->make($periodName, $parameters->date, 'UTC');
            $period = $periods[0];
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        $this->scheduler->schedule(
            requester: $login,
            idSites: $idSites,
            startDateTime: $period->startDate.' 00:00:00',
            endDateTime: $period->endDate.' 23:59:59',
            anonymizeIp: $parameters->anonymizeIp,
            anonymizeLocation: $parameters->anonymizeLocation,
            anonymizeUserId: $parameters->anonymizeUserId,
            visitColumns: $parameters->visitColumns,
            actionColumns: $parameters->actionColumns,
        );

        return $this->responses->success($request);
    }

    /**
     * @param  list<string>|null  $requested
     * @return list<int>|null|false
     */
    private function siteIds(?array $requested): array|null|false
    {
        if (in_array($requested, [null, [], [''], ['all']], true)) {
            return null;
        }

        $available = $this->sites->allIds();
        $result = [];
        foreach ($requested as $site) {
            if (preg_match('/^[1-9][0-9]*$/D', $site) !== 1 || ! in_array((int) $site, $available, true)) {
                return false;
            }

            $result[] = (int) $site;
        }

        return array_values(array_unique($result));
    }

    /** @param list<string> $requested */
    private function invalidColumn(array $requested, string $table): ?string
    {
        if ($requested === []) {
            return null;
        }

        $available = array_column($this->columns->forTable($table), 'column_name');
        foreach ($requested as $column) {
            if (! in_array($column, $available, true)) {
                return sprintf('The column "%s" does not exist in %s or cannot be unset.', $column, $table);
            }
        }

        return null;
    }
}
