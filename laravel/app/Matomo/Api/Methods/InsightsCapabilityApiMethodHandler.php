<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\ReportingPeriodFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class InsightsCapabilityApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiResponseFactory $responses,
        private ApiAccessAuthorizer $authorizer,
        private ReportingPeriodFactory $periods,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isInsightsRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Insights capability API handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for at least one website.",
                401,
            );
        }

        $parameters = $request->insights
            ?? throw new LogicException('The Insights parameters were not parsed.');

        if (preg_match('/^(last|previous)[0-9]*$/D', $parameters->date) === 1) {
            return $this->responses->scalar($request, false);
        }

        try {
            [$current] = $this->periods->make($parameters->period, $parameters->date, 'UTC');

            if ($current === []) {
                return $this->responses->scalar($request, false);
            }

            $first = $current[0];
            $comparisonDate = $parameters->period === 'range'
                ? $first->startDate.','.$first->endDate
                : $first->startDate;
            $this->periods->make($parameters->period, $comparisonDate, 'UTC');
        } catch (InvalidArgumentException) {
            return $this->responses->scalar($request, false);
        }

        return $this->responses->scalar($request, true);
    }
}
