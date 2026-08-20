<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Api\ProcessedReportRequest;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReportMetadataCatalog;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class ProcessedReportApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private Application $application,
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private ReportMetadataCatalog $metadata,
        private SiteRepository $sites,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->processedReport !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $startedAt = hrtime(true);
        $parameters = $request->processedReport
            ?? throw new LogicException('The processed report parameters are missing.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->siteId)) {
            return $this->responses->error($request, "You do not have view access to website {$parameters->siteId}.", 401);
        }

        $language = $parameters->language
            ?? $this->languages->resolve($httpRequest, $request->authentication);
        $metadata = $this->metadata->find(
            $parameters->apiModule,
            $parameters->apiAction,
            $language,
            $parameters->hideMetricsDocumentation,
        );
        if ($metadata === null) {
            return $this->responses->error(
                $request,
                "Requested report {$parameters->apiModule}.{$parameters->apiAction} was not found.",
                400,
            );
        }

        $unsupported = $this->unsupportedReason($parameters, $metadata);
        if ($unsupported !== null) {
            return $this->responses->error($request, $unsupported, 501);
        }

        $nestedParameters = $this->nestedParameters($parameters, $httpRequest, $language);
        $nestedRequest = Request::create('/index.php', 'GET', $nestedParameters);

        try {
            $nestedApiRequest = ApiRequest::fromRequestWithAuthentication(
                $nestedRequest,
                $request->authentication,
            );
        } catch (InvalidApiParameter|MissingApiParameter $invalidRequest) {
            return $this->responses->error($request, $invalidRequest->getMessage(), 400);
        }

        $dispatcher = $this->application->make(ApiMethodDispatcher::class);
        if (! $dispatcher->supports($nestedApiRequest)) {
            return $this->responses->error(
                $request,
                'The requested report has not moved to Laravel yet.',
                501,
            );
        }

        $response = $dispatcher->dispatch($nestedApiRequest, $nestedRequest);
        $content = $response->getContent();
        if ($content === false) {
            throw new RuntimeException('The report response body could not be read.');
        }

        $reportData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if ($response->isClientError() || $response->isServerError()) {
            $message = is_array($reportData) && is_string($reportData['message'] ?? null)
                ? $reportData['message']
                : 'The requested report could not be generated.';

            return $this->responses->error($request, $message, $response->getStatusCode());
        }

        $columns = $this->columns($metadata);
        $reportData = $this->processedData($reportData, array_keys($columns));
        $result = [
            'website' => $this->siteName($parameters->siteId),
            'prettyDate' => $this->prettyDate($parameters, $language),
            'metadata' => $metadata,
            'columns' => $columns,
            'reportData' => $reportData,
            'reportMetadata' => [],
            'reportTotal' => $this->totals($reportData, array_keys($columns)),
        ];

        if ($parameters->showTimer) {
            $result['timerMillis'] = round((hrtime(true) - $startedAt) / 1_000_000, 3);
        }

        return $this->responses->structured($request, $result);
    }

    /** @param array<string, mixed> $metadata */
    private function unsupportedReason(ProcessedReportRequest $parameters, array $metadata): ?string
    {
        if ($parameters->period !== 'day') {
            return 'Processed report metadata for non-day periods has not moved to Laravel yet.';
        }

        if ($parameters->apiParameters !== []
            || $parameters->goalId !== null
            || $parameters->subtableId !== null
            || $parameters->dimensionId !== null) {
            return 'Processed report metadata variants have not moved to Laravel yet.';
        }

        if ($parameters->showRawMetrics || $parameters->formatMetrics !== null) {
            return 'Processed report metric-format variants have not moved to Laravel yet.';
        }

        if (isset($metadata['dimension'])) {
            return 'Processed table reports and row metadata have not moved to Laravel yet.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function nestedParameters(
        ProcessedReportRequest $parameters,
        Request $httpRequest,
        string $language,
    ): array {
        $nested = array_replace($httpRequest->request->all(), $httpRequest->query->all());
        foreach ([
            'module',
            'method',
            'format',
            'callback',
            'jsoncallback',
            'serialize',
            'token_auth',
            'force_api_session',
            'apiModule',
            'apiAction',
            'apiParameters',
            'showTimer',
            'hideMetricsDoc',
            'showRawMetrics',
            'format_metrics',
        ] as $name) {
            unset($nested[$name]);
        }

        $nested = [
            ...$nested,
            ...$parameters->apiParameters,
            'module' => 'API',
            'method' => $parameters->apiModule.'.'.$parameters->apiAction,
            'idSite' => $parameters->siteId,
            'period' => $parameters->period,
            'date' => $parameters->date,
            'language' => $language,
            'format' => 'json',
            'format_metrics' => 'bc',
        ];

        if ($parameters->segment !== null) {
            $nested['segment'] = $parameters->segment;
        }

        return $nested;
    }

    private function siteName(int $siteId): string
    {
        $name = $this->sites->details($siteId)['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    private function prettyDate(ProcessedReportRequest $parameters, string $language): string
    {
        $timezone = $this->sites->timezone($parameters->siteId) ?? 'UTC';

        try {
            if (str_contains($parameters->date, ',')) {
                [$start, $end] = explode(',', $parameters->date, 2);

                return $this->localizedDate($start, $timezone, $language, 'll')
                    .' - '.$this->localizedDate($end, $timezone, $language, 'll');
            }

            return $this->localizedDate(
                $parameters->date,
                $timezone,
                $language,
                'dddd, MMMM D, YYYY',
            );
        } catch (Throwable) {
            return $parameters->date;
        }
    }

    private function localizedDate(
        string $date,
        string $timezone,
        string $language,
        string $format,
    ): string {
        $localized = CarbonImmutable::parse($date, $timezone)->settings(['locale' => $language]);

        return $localized->isoFormat($format);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function columns(array $metadata): array
    {
        $columns = [];
        foreach (['metrics', 'processedMetrics'] as $key) {
            if (! isset($metadata[$key]) || ! is_array($metadata[$key])) {
                continue;
            }

            foreach ($metadata[$key] as $id => $name) {
                if (is_string($id) && is_string($name)) {
                    $columns[$id] = $name;
                }
            }
        }

        return $columns;
    }

    /** @param list<string> $metrics */
    private function processedData(mixed $data, array $metrics): mixed
    {
        if (! is_array($data) || $data === []) {
            return $data;
        }

        if (array_is_list($data)) {
            return array_map(fn (mixed $row): mixed => $this->processedData($row, $metrics), $data);
        }

        if ($this->isMetricRow($data, $metrics)) {
            $row = [];
            foreach ($metrics as $metric) {
                $value = $data[$metric] ?? 0;
                $row[$metric] = is_scalar($value) ? $value : 0;
            }

            return $row;
        }

        foreach ($data as $key => $value) {
            $data[$key] = $this->processedData($value, $metrics);
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $metrics
     */
    private function isMetricRow(array $data, array $metrics): bool
    {
        foreach ($metrics as $metric) {
            if (array_key_exists($metric, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $metrics
     * @return array<array-key, mixed>
     */
    private function totals(mixed $data, array $metrics): array
    {
        if (! is_array($data) || $data === []) {
            return [];
        }

        if ($this->isMetricRow($data, $metrics)) {
            $totals = [];
            foreach ($metrics as $metric) {
                $value = $data[$metric] ?? null;
                if (is_int($value) || is_float($value)) {
                    $totals[$metric] = $value;
                }
            }

            return $totals;
        }

        if (array_is_list($data)) {
            $totals = [];
            foreach ($data as $row) {
                foreach ($this->totals($row, $metrics) as $metric => $value) {
                    if (is_string($metric) && (is_int($value) || is_float($value))) {
                        $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                    }
                }
            }

            return $totals;
        }

        $totals = [];
        foreach ($data as $key => $value) {
            $totals[$key] = $this->totals($value, $metrics);
        }

        return $totals;
    }
}
