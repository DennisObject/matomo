<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\Methods\ApiMethodDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

final readonly class LaravelScheduledReportGenerator implements ScheduledReportGenerator
{
    public function __construct(private Application $application) {}

    public function generate(array $report, string $date, string $period, Request $request): string
    {
        $selected = $report['reports'] ?? [];
        if (! is_array($selected)) {
            throw new ScheduledReportException('The scheduled report selection is invalid.');
        }

        $rendered = [];
        foreach ($selected as $reportId) {
            if (! is_string($reportId) || preg_match('/^[A-Za-z][A-Za-z0-9]*_get[A-Za-z0-9]*$/D', $reportId) !== 1) {
                throw new ScheduledReportException('A selected report identifier is invalid.');
            }

            [$module, $action] = explode('_', $reportId, 2);
            $input = [
                ...$request->all(),
                'module' => 'API', 'method' => $module.'.'.$action, 'format' => 'json',
                'idSite' => (int) ($report['idsite'] ?? 0), 'period' => $period, 'date' => $date,
            ];
            $nestedRequest = Request::create('/index.php', 'GET', $input);
            $apiRequest = ApiRequest::fromRequest($nestedRequest);
            $response = $this->application->make(ApiMethodDispatcher::class)->dispatch($apiRequest, $nestedRequest);
            if ($response->getStatusCode() >= 400) {
                throw new ScheduledReportException("The report {$reportId} could not be generated.");
            }

            $decoded = json_decode((string) $response->getContent(), true);
            $rendered[$reportId] = $decoded ?? (string) $response->getContent();
        }

        $title = htmlspecialchars((string) ($report['description'] ?? 'Scheduled report'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = htmlspecialchars(json_encode($rendered, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$title}</title></head>".
            "<body><h1>{$title}</h1><pre>{$body}</pre></body></html>";
    }
}
