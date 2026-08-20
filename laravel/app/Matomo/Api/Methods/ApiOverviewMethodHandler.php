<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReportMetadataCatalog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use LogicException;
use RuntimeException;

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
        $columnsByModule = $this->columnsByModule(
            $this->metadata->all($language, true),
            $parameters->columns,
        );
        $nestedRequests = [];
        $dispatcher = $this->application->make(ApiMethodDispatcher::class);

        foreach ($columnsByModule as $module => $columns) {
            $nestedRequest = Request::create('/index.php', 'GET', $this->nestedParameters(
                $httpRequest,
                $module,
                $columns,
                $parameters->siteId,
                $parameters->period,
                $parameters->date,
                $parameters->segment,
            ));

            try {
                $nestedApiRequest = ApiRequest::fromRequestWithAuthentication(
                    $nestedRequest,
                    $request->authentication,
                );
            } catch (InvalidApiParameter|MissingApiParameter $invalidRequest) {
                return $this->responses->error($request, $invalidRequest->getMessage(), 400);
            }

            if (! $dispatcher->supports($nestedApiRequest)) {
                return $this->responses->error(
                    $request,
                    "The overview metrics provided by {$module}.get have not moved to Laravel yet.",
                    501,
                );
            }

            $nestedRequests[] = [$nestedApiRequest, $nestedRequest, $columns];
        }

        $result = [];
        foreach ($nestedRequests as [$nestedApiRequest, $nestedRequest, $columns]) {
            $nestedResponse = $dispatcher->dispatch($nestedApiRequest, $nestedRequest);
            $data = $this->decodedContent($nestedResponse);
            if ($nestedResponse->isClientError() || $nestedResponse->isServerError()) {
                $message = is_array($data) && is_string($data['message'] ?? null)
                    ? $data['message']
                    : 'An overview report could not be generated.';

                return $this->responses->error($request, $message, $nestedResponse->getStatusCode());
            }

            if (! is_array($data)) {
                return $this->responses->error(
                    $request,
                    'An overview report returned an unsupported result.',
                    500,
                );
            }

            $this->mergeReport($result, $data, array_fill_keys($columns, true));
        }

        $dimensions = $result !== [] && ! $this->containsMetric($result, $parameters->columns)
            ? ['date']
            : [];

        return $this->responses->report($request, new ApiReport($result, $dimensions));
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @param  list<string>  $requestedColumns
     * @return array<string, list<string>>
     */
    private function columnsByModule(array $reports, array $requestedColumns): array
    {
        $requested = array_fill_keys($requestedColumns, true);
        $columnsByModule = [];

        foreach ($reports as $report) {
            if (($report['action'] ?? null) !== 'get' || isset($report['parameters'])) {
                continue;
            }

            $module = $this->moduleName($report);
            if ($module === null || $module === 'API') {
                continue;
            }

            $columns = [];
            foreach (['metrics', 'processedMetrics'] as $key) {
                if (! isset($report[$key]) || ! is_array($report[$key])) {
                    continue;
                }

                foreach (array_keys($report[$key]) as $column) {
                    if (is_string($column) && ($requested === [] || isset($requested[$column]))) {
                        $columns[] = $column;
                    }
                }
            }

            if ($columns !== []) {
                $columnsByModule[$module] = array_values(array_unique([
                    ...($columnsByModule[$module] ?? []),
                    ...$columns,
                ]));
            }
        }

        krsort($columnsByModule);

        return $columnsByModule;
    }

    /** @param array<string, mixed> $report */
    private function moduleName(array $report): ?string
    {
        $uniqueId = $report['uniqueId'] ?? null;
        if (is_string($uniqueId) && preg_match('/^([A-Za-z][A-Za-z0-9]*)_/', $uniqueId, $matches) === 1) {
            return $matches[1];
        }

        $module = $report['module'] ?? null;

        return is_string($module) && preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $module) === 1
            ? $module
            : null;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function nestedParameters(
        Request $httpRequest,
        string $module,
        array $columns,
        int $siteId,
        string $period,
        string $date,
        ?string $segment,
    ): array {
        $nested = [
            'module' => 'API',
            'method' => $module.'.get',
            'format' => 'json',
            'idSite' => $siteId,
            'period' => $period,
            'date' => $date,
            'columns' => implode(',', $columns),
        ];
        if ($segment !== null) {
            $nested['segment'] = $segment;
        }

        $outer = array_replace($httpRequest->request->all(), $httpRequest->query->all());
        if (array_key_exists('format_metrics', $outer)) {
            $nested['format_metrics'] = $outer['format_metrics'];
        }

        return $nested;
    }

    private function decodedContent(Response $response): mixed
    {
        $content = $response->getContent();
        if ($content === false) {
            throw new RuntimeException('The overview report response body could not be read.');
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('The overview report response was not valid JSON.', 0, $jsonException);
        }
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @param  array<array-key, mixed>  $source
     * @param  array<string, true>  $columns
     */
    private function mergeReport(array &$target, array $source, array $columns): void
    {
        foreach ($source as $key => $value) {
            if (is_string($key) && isset($columns[$key])) {
                if (is_scalar($value) || $value === null) {
                    $target[$key] = $value;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $nested = isset($target[$key]) && is_array($target[$key]) ? $target[$key] : [];
            $this->mergeReport($nested, $value, $columns);
            if ($nested !== []) {
                $target[$key] = $nested;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $requestedColumns
     */
    private function containsMetric(array $data, array $requestedColumns): bool
    {
        if ($requestedColumns !== []) {
            foreach ($requestedColumns as $column) {
                if (array_key_exists($column, $data)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($data as $value) {
            if (! is_array($value)) {
                return true;
            }
        }

        return false;
    }
}
