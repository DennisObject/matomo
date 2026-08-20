<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Api\RowEvolutionRequest;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReportMetadataCatalog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

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
        $parameters = $request->rowEvolution
            ?? throw new LogicException('The row evolution parameters are missing.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->siteId)) {
            return $this->responses->error(
                $request,
                "You do not have view access to website {$parameters->siteId}.",
                401,
            );
        }

        $unsupported = $this->unsupportedReason($parameters);
        if ($unsupported !== null) {
            return $this->responses->error($request, $unsupported, 501);
        }

        $language = $parameters->language
            ?? $this->languages->resolve($httpRequest, $request->authentication);
        $metadata = $this->metadata->find(
            $parameters->apiModule,
            $parameters->apiAction,
            $language,
            false,
        );
        if ($metadata === null) {
            return $this->responses->error(
                $request,
                "Requested report {$parameters->apiModule}.{$parameters->apiAction} was not found.",
                400,
            );
        }

        $dimension = $metadata['dimension'] ?? null;
        $metricNames = $this->metricNames($metadata);
        if (! is_string($dimension) || $dimension === '' || $metricNames === []) {
            return $this->responses->error(
                $request,
                "Report {$parameters->apiModule}.{$parameters->apiAction} is not supported by row evolution.",
                501,
            );
        }

        $nestedRequest = Request::create('/index.php', 'GET', $this->nestedParameters($parameters, $language));
        try {
            $nestedApiRequest = ApiRequest::fromRequestWithAuthentication(
                $nestedRequest,
                $request->authentication,
            );
        } catch (InvalidApiParameter|MissingApiParameter $invalidRequest) {
            return $this->responses->error($request, $invalidRequest->getMessage(), 400);
        }

        $dispatcher = $this->application->make(ApiMethodDispatcher::class);
        if ($nestedApiRequest->rowEvolution !== null || ! $dispatcher->supports($nestedApiRequest)) {
            return $this->responses->error(
                $request,
                'The requested row evolution report has not moved to Laravel yet.',
                501,
            );
        }

        $response = $dispatcher->dispatch($nestedApiRequest, $nestedRequest);
        $content = $response->getContent();
        if ($content === false) {
            throw new RuntimeException('The row evolution report response body could not be read.');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->responses->error(
                $request,
                'The requested row evolution report returned an invalid response.',
                500,
            );
        }

        if ($response->isClientError() || $response->isServerError()) {
            $message = is_array($data) && is_string($data['message'] ?? null)
                ? $data['message']
                : 'The requested row evolution report could not be generated.';

            return $this->responses->error($request, $message, $response->getStatusCode());
        }

        if (! is_array($data) || array_is_list($data)) {
            return $this->responses->error(
                $request,
                'Row evolution requires a date that resolves to multiple periods.',
                400,
            );
        }

        try {
            [$series, $selectedRows, $actualLabel] = $this->series(
                $data,
                $parameters,
                array_keys($metricNames),
            );
        } catch (UnexpectedValueException $unexpectedValueException) {
            return $this->responses->error($request, $unexpectedValueException->getMessage(), 501);
        }

        return $this->responses->structured($request, [
            'label' => $actualLabel,
            'reportData' => $series,
            'metadata' => [
                'metrics' => $this->metricMetadata($metricNames, $selectedRows),
                'dimension' => $dimension,
            ],
        ]);
    }

    private function unsupportedReason(RowEvolutionRequest $parameters): ?string
    {
        if ($parameters->hasUnsupportedVariants) {
            return 'Goal, dimension, and comparison-series row evolution variants have not moved to Laravel yet.';
        }

        if ($parameters->label === null) {
            return 'Automatic and multi-row evolution labels have not moved to Laravel yet.';
        }

        if (str_contains($parameters->label, ',') || str_contains($parameters->label, '>')) {
            return 'Multi-row and recursive row evolution labels have not moved to Laravel yet.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function nestedParameters(RowEvolutionRequest $parameters, string $language): array
    {
        $nested = [
            'module' => 'API',
            'method' => $parameters->apiModule.'.'.$parameters->apiAction,
            'format' => 'json',
            'idSite' => $parameters->siteId,
            'period' => $parameters->period === 'range' ? 'day' : $parameters->period,
            'date' => $parameters->date,
            'language' => $language,
            'format_metrics' => '0',
            'showMetadata' => '1',
        ];

        if ($parameters->segment !== null) {
            $nested['segment'] = $parameters->segment;
        }

        return $nested;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $metrics
     * @return array{array<string, list<array<string, mixed>>>, list<array<string, mixed>|null>, string}
     */
    private function series(array $data, RowEvolutionRequest $parameters, array $metrics): array
    {
        $series = [];
        $selectedRows = [];
        $actualLabel = $parameters->label ?? '';

        foreach ($data as $date => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new UnexpectedValueException(
                    'The requested report does not return a date-indexed table that row evolution supports.',
                );
            }

            $selected = null;
            foreach ($rows as $row) {
                if (is_array($row) && (string) ($row['label'] ?? '') === $parameters->label) {
                    $selected = $row;
                    break;
                }
            }

            if ($selected === null) {
                $series[$date] = [];
                $selectedRows[] = null;

                continue;
            }

            $actualLabel = $this->actualLabel($selected, $parameters);
            $allowed = [...$metrics, 'label_html'];
            $selected = array_filter(
                $selected,
                static fn (string $name): bool => in_array($name, $allowed, true),
                ARRAY_FILTER_USE_KEY,
            );
            $series[$date] = [$selected];
            $selectedRows[] = $selected;
        }

        return [$series, $selectedRows, $actualLabel];
    }

    /** @param array<string, mixed> $row */
    private function actualLabel(array $row, RowEvolutionRequest $parameters): string
    {
        $url = $row['url'] ?? null;
        if ($parameters->labelUseAbsoluteUrl
            && is_string($url)
            && $url !== ''
            && ($parameters->apiModule === 'Actions'
                || ($parameters->apiModule === 'Referrers' && $parameters->apiAction === 'getWebsites'))) {
            return preg_replace(';^https?://(?:www\.)?;i', '', $url) ?? $url;
        }

        $label = $row['label'] ?? $parameters->label;

        return is_scalar($label) ? (string) $label : ($parameters->label ?? '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function metricNames(array $metadata): array
    {
        $metrics = [];
        foreach (['metrics', 'processedMetrics'] as $key) {
            if (! isset($metadata[$key]) || ! is_array($metadata[$key])) {
                continue;
            }

            foreach ($metadata[$key] as $id => $name) {
                if (is_string($id) && is_string($name)) {
                    $metrics[$id] = $name;
                }
            }
        }

        return $metrics;
    }

    /**
     * @param  array<string, string>  $metricNames
     * @param  list<array<string, mixed>|null>  $rows
     * @return array<string, array<string, float|int|string>>
     */
    private function metricMetadata(array $metricNames, array $rows): array
    {
        $result = [];
        foreach ($metricNames as $metric => $name) {
            $result[$metric] = ['name' => $name];
            $foundNonZero = false;

            foreach ($rows as $row) {
                $value = $this->numericValue($row[$metric] ?? 0);
                if ($value > 0) {
                    $foundNonZero = true;
                } elseif (! $foundNonZero) {
                    continue;
                }

                $result[$metric]['min'] = isset($result[$metric]['min'])
                    ? min($result[$metric]['min'], $value)
                    : $value;
                $result[$metric]['max'] = isset($result[$metric]['max'])
                    ? max($result[$metric]['max'], $value)
                    : $value;
            }

            $first = $this->numericValue(($rows[0] ?? null)[$metric] ?? 0);
            $last = $this->numericValue(($rows[count($rows) - 1] ?? null)[$metric] ?? 0);
            if ($first != 0) {
                $change = (int) round((($last - $first) / $first) * 100);
                $result[$metric]['change'] = ($change >= 0 ? '+' : '').$change.'%';
            }
        }

        return $result;
    }

    private function numericValue(mixed $value): float|int
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : 0;
    }
}
