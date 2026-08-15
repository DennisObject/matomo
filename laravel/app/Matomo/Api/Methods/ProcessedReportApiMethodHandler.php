<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
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
        $parameters = $request->processedReport
            ?? throw new LogicException('The processed report parameters are missing.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->siteId)) {
            return $this->responses->error($request, "You do not have view access to website {$parameters->siteId}.", 401);
        }

        $nestedParameters = [
            ...$parameters->apiParameters,
            'module' => 'API',
            'method' => $parameters->apiModule.'.'.$parameters->apiAction,
            'idSite' => $parameters->siteId,
            'period' => $parameters->period,
            'date' => $parameters->date,
            'format' => 'json',
        ];
        foreach (['segment', 'idGoal', 'idSubtable', 'idDimension', 'token_auth', 'force_api_session'] as $name) {
            if ($httpRequest->query->has($name)) {
                $nestedParameters[$name] = $httpRequest->query($name);
            }
        }

        $nestedRequest = Request::create('/index.php', 'GET', $nestedParameters);
        $nestedApiRequest = ApiRequest::fromRequest($nestedRequest);
        $response = $this->application->make(ApiMethodDispatcher::class)->dispatch($nestedApiRequest, $nestedRequest);
        $content = $response->getContent();
        if ($content === false) {
            throw new RuntimeException('The report response body could not be read.');
        }

        $reportData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $metadata = $this->metadata->find(
            $parameters->apiModule,
            $parameters->apiAction,
            $language,
            $parameters->hideMetricsDocumentation,
        ) ?? [];

        return $this->responses->structured($request, [
            'website' => $this->siteName($parameters->siteId),
            'prettyDate' => $this->prettyDate($parameters->date),
            'metadata' => $metadata,
            'columns' => $this->columns($metadata),
            'reportData' => $reportData,
            'reportMetadata' => [],
            'reportTotal' => $this->totals($reportData),
        ]);
    }

    private function siteName(int $siteId): string
    {
        $name = $this->sites->details($siteId)['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    private function prettyDate(string $date): string
    {
        if (str_contains($date, ',')) {
            return $date;
        }

        try {
            return CarbonImmutable::parse($date)->isoFormat('dddd, MMMM D, YYYY');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function columns(array $metadata): array
    {
        $columns = [];
        if (isset($metadata['dimension']) && is_string($metadata['dimension'])) {
            $columns['label'] = $metadata['dimension'];
        }

        foreach (['metrics', 'processedMetrics'] as $key) {
            if (isset($metadata[$key]) && is_array($metadata[$key])) {
                foreach ($metadata[$key] as $id => $name) {
                    if (is_string($id) && is_string($name)) {
                        $columns[$id] = $name;
                    }
                }
            }
        }

        return $columns;
    }

    /** @return array<string, float|int> */
    private function totals(mixed $reportData): array
    {
        if (! is_array($reportData)) {
            return [];
        }

        $rows = array_is_list($reportData) ? $reportData : [$reportData];
        $totals = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $metric => $value) {
                if (is_string($metric) && (is_int($value) || is_float($value))) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }
}
