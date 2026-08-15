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

final readonly class RowEvolutionApiMethodHandler implements ApiMethodHandler
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
        return $request->rowEvolution !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->rowEvolution ?? throw new LogicException('The row evolution parameters are missing.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->siteId)) {
            return $this->responses->error($request, "You do not have view access to website {$parameters->siteId}.", 401);
        }

        $query = [
            'module' => 'API', 'method' => $parameters->apiModule.'.'.$parameters->apiAction,
            'format' => 'json', 'idSite' => $parameters->siteId,
            'period' => $parameters->period, 'date' => $parameters->date,
        ];
        foreach (['segment', 'token_auth', 'force_api_session'] as $name) {
            $value = $name === 'segment' ? $parameters->segment : $httpRequest->query($name);
            if ($value !== null && $value !== '') {
                $query[$name] = $value;
            }
        }

        $nestedRequest = Request::create('/index.php', 'GET', $query);
        $nestedApiRequest = ApiRequest::fromRequest($nestedRequest);
        $response = $this->application->make(ApiMethodDispatcher::class)->dispatch($nestedApiRequest, $nestedRequest);
        $content = $response->getContent();
        $data = $content === false ? [] : json_decode($content, true);
        $series = $this->series(is_array($data) ? $data : [], $parameters->label, $parameters->column);
        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $report = $this->metadata->find($parameters->apiModule, $parameters->apiAction, $language, true) ?? [];

        return $this->responses->structured($request, [
            'label' => $parameters->label ?? ($parameters->column ?? ''),
            'reportData' => $series,
            'metadata' => [
                'metrics' => $this->metricMetadata($report, $series),
                'dimension' => $report['dimension'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function series(array $data, ?string $label, ?string $column): array
    {
        $mapped = ! array_is_list($data);
        $dates = $mapped ? $data : ['value' => $data];
        $result = [];
        foreach ($dates as $date => $rows) {
            if ($label !== null && is_array($rows) && array_is_list($rows)) {
                $rows = array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && ($row['label'] ?? null) === $label));
            }

            if ($column !== null) {
                $rows = $this->columnValue($rows, $column);
            }

            $result[$date] = $rows;
        }

        return $result;
    }

    private function columnValue(mixed $rows, string $column): mixed
    {
        if (is_array($rows) && array_is_list($rows)) {
            return $rows[0][$column] ?? null;
        }

        return is_array($rows) ? ($rows[$column] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<array-key, mixed>  $series
     * @return array<string, array<string, mixed>>
     */
    private function metricMetadata(array $report, array $series): array
    {
        $metrics = [];
        foreach (['metrics', 'processedMetrics'] as $key) {
            if (! isset($report[$key]) || ! is_array($report[$key])) {
                continue;
            }

            foreach ($report[$key] as $id => $name) {
                if (is_string($id) && is_string($name)) {
                    $metrics[$id] = ['name' => $name];
                }
            }
        }

        return $metrics;
    }
}
