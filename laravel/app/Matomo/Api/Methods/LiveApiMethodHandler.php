<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Live\LiveAccessPolicy;
use App\Matomo\Live\LiveCounterRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class LiveApiMethodHandler implements ApiMethodHandler
{
    private const array COUNTER_COLUMNS = ['visits', 'actions', 'visitors', 'visitsConverted'];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LiveAccessPolicy $access,
        private LiveCounterRepository $counters,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->live !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Live API handler does not support this request.');
        }

        $parameters = $request->live ?? throw new LogicException('The Live parameters were not parsed.');
        $viewable = $this->authorizer->siteIdsWithAtLeastViewAccess(
            $request->authentication,
            $request->restrictSitesToLogin,
        );
        $siteIds = $parameters->allSites ? $viewable : $parameters->siteIds;
        if (! $parameters->allSites && array_diff($siteIds, $viewable) !== []) {
            return $this->responses->error($request, 'View access is required for every requested website.', 401);
        }

        if ($request->method === 'Live.isVisitorProfileEnabled') {
            $enabled = $siteIds !== [];
            foreach ($siteIds as $siteId) {
                $enabled = $enabled && $this->access->visitorProfileEnabled($siteId);
            }

            return $this->responses->scalar($request, $enabled);
        }

        try {
            $values = $this->counters->counters(
                $siteIds,
                $parameters->lastMinutes ?? throw new LogicException('The counter window is missing.'),
                $parameters->segment,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        $values = array_filter(
            $values,
            fn (int $value, string $column): bool => $this->visible($column, $parameters->showColumns, $parameters->hideColumns),
            ARRAY_FILTER_USE_BOTH,
        );

        return $this->responses->rows($request, [$values]);
    }

    /**
     * @param  list<string>  $show
     * @param  list<string>  $hide
     */
    private function visible(string $column, array $show, array $hide): bool
    {
        return in_array($column, self::COUNTER_COLUMNS, true)
            && ($show === [] || in_array($column, $show, true))
            && ! in_array($column, $hide, true);
    }
}
