<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReportMetadataCatalog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class ApiOverviewMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private Application $application,
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private ReportMetadataCatalog $metadata,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->apiOverview !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->apiOverview ?? throw new LogicException('The API overview parameters are missing.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->siteId)) {
            return $this->responses->error($request, "You do not have view access to website {$parameters->siteId}.", 401);
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $result = [];
        foreach ($this->metadata->all($language, true) as $report) {
            if (($report['action'] ?? null) !== 'get' || isset($report['parameters']) || ($report['module'] ?? null) === 'API') {
                continue;
            }

            if ($parameters->columns !== [] && ! $this->providesRequestedColumn($report, $parameters->columns)) {
                continue;
            }

            $module = $report['module'] ?? null;
            if (! is_string($module)) {
                continue;
            }

            $nestedParameters = [
                'module' => 'API', 'method' => $module.'.get', 'format' => 'json',
                'idSite' => $parameters->siteId, 'period' => $parameters->period, 'date' => $parameters->date,
            ];
            if ($parameters->segment !== null) {
                $nestedParameters['segment'] = $parameters->segment;
            }

            foreach (['token_auth', 'force_api_session'] as $name) {
                if ($httpRequest->query->has($name)) {
                    $nestedParameters[$name] = $httpRequest->query($name);
                }
            }

            $nestedRequest = Request::create('/index.php', 'GET', $nestedParameters);
            $nestedApiRequest = ApiRequest::fromRequest($nestedRequest);
            $dispatcher = $this->application->make(ApiMethodDispatcher::class);
            if (! $dispatcher->supports($nestedApiRequest)) {
                continue;
            }

            $nestedResponse = $dispatcher->dispatch($nestedApiRequest, $nestedRequest);
            $content = $nestedResponse->getContent();
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (! is_array($data) || array_is_list($data)) {
                continue;
            }

            foreach ($data as $column => $value) {
                if (is_string($column) && ($parameters->columns === [] || in_array($column, $parameters->columns, true))) {
                    $result[$column] = $value;
                }
            }
        }

        return $this->responses->structured($request, $result);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $columns
     */
    private function providesRequestedColumn(array $report, array $columns): bool
    {
        foreach (['metrics', 'processedMetrics'] as $key) {
            if (! isset($report[$key]) || ! is_array($report[$key])) {
                continue;
            }

            if (array_intersect(array_keys($report[$key]), $columns) !== []) {
                return true;
            }
        }

        return false;
    }
}
