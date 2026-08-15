<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ScheduledReportsRequest;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\MobileMessaging\MobileMessagingException;
use App\Matomo\MobileMessaging\MobileMessagingManager;
use App\Matomo\ScheduledReports\ScheduledReportDocumentRenderer;
use App\Matomo\ScheduledReports\ScheduledReportException;
use App\Matomo\ScheduledReports\ScheduledReportGenerator;
use App\Matomo\ScheduledReports\ScheduledReportManager;
use App\Matomo\ScheduledReports\ScheduledReportSender;
use App\Matomo\Users\UserDirectoryRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class ScheduledReportsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private ScheduledReportManager $reports,
        private ScheduledReportGenerator $generator,
        private ScheduledReportSender $sender,
        private ScheduledReportDocumentRenderer $documents,
        private DashboardRepository $dashboards,
        private UserDirectoryRepository $users,
        private MobileMessagingManager $mobileMessaging,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->scheduledReports !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->scheduledReports
            ?? throw new LogicException('The ScheduledReports API parameters are missing.');
        $login = $this->authorizer->authenticatedLogin($request->authentication);
        if (in_array($login, [null, '', 'anonymous'], true)) {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);
        if ($parameters->idSite !== null
            && ! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->idSite)) {
            return $this->responses->error($request, 'You do not have view access to this site.', 401);
        }

        if ($parameters->idSite === null && ! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error($request, 'You need view access to at least one site.', 401);
        }

        try {
            return match ($request->method) {
                'ScheduledReports.addReport' => $this->responses->scalar(
                    $request,
                    $this->reports->add($login, $this->attributes($parameters)),
                ),
                'ScheduledReports.updateReport' => $this->updated($request, $parameters, $login, $superUser),
                'ScheduledReports.deleteReport' => $this->deleted($request, $parameters, $login, $superUser),
                'ScheduledReports.getWidgetReportMap' => $this->widgetMap($request, $parameters, $login),
                'ScheduledReports.getReports' => $this->responses->structured($request, $this->reports->get(
                    $parameters->idSite, $parameters->period, $parameters->idReport, $parameters->idSegment,
                    $login, $superUser, $parameters->onlyOwnReports,
                )),
                'ScheduledReports.generateReport' => $this->generate(
                    $request, $parameters, $login, $superUser, $httpRequest,
                ),
                'ScheduledReports.sendReport' => $this->send(
                    $request, $parameters, $login, $superUser, $httpRequest,
                ),
                default => throw new LogicException('The ScheduledReports API method is not implemented.'),
            };
        } catch (ScheduledReportException|MobileMessagingException $scheduledReportException) {
            return $this->responses->error($request, $scheduledReportException->getMessage(), 400);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(ScheduledReportsRequest $parameters): array
    {
        return [
            'idsite' => $parameters->idSite,
            'description' => $parameters->description,
            'idsegment' => $parameters->idSegment,
            'period' => $parameters->period,
            'period_param' => $parameters->periodParam,
            'hour' => $parameters->hour,
            'type' => $parameters->reportType,
            'format' => $parameters->reportFormat,
            'reports' => $parameters->reports,
            'parameters' => $parameters->parameters,
            'evolution_graph_within_period' => $parameters->evolutionPeriodFor === 'each',
            'evolution_graph_period_n' => $parameters->evolutionPeriodN ?? 30,
        ];
    }

    private function updated(ApiRequest $request, ScheduledReportsRequest $parameters, string $login, bool $superUser): Response
    {
        $this->reports->update($parameters->idReport ?? 0, $login, $superUser, $this->attributes($parameters));

        return $this->responses->scalar($request, true);
    }

    private function deleted(ApiRequest $request, ScheduledReportsRequest $parameters, string $login, bool $superUser): Response
    {
        $this->reports->delete($parameters->idReport ?? 0, $login, $superUser);

        return $this->responses->scalar($request, true);
    }

    private function widgetMap(ApiRequest $request, ScheduledReportsRequest $parameters, string $login): Response
    {
        $dashboard = $this->dashboards->all($login);
        $selected = array_values(array_filter(
            $dashboard,
            static fn (array $row): bool => $row['iddashboard'] === $parameters->dashboardId,
        ));
        $name = (string) ($selected[0]['name'] ?? '');
        $layout = (string) ($selected[0]['layout'] ?? '[]');
        $reportIds = $this->widgetReportIds($layout);

        return $this->responses->structured($request, [
            'dashboardName' => $name,
            'email' => array_fill_keys($reportIds, true),
            'idSegment' => null,
            'unmappedWidgets' => [],
        ]);
    }

    private function generate(
        ApiRequest $request,
        ScheduledReportsRequest $parameters,
        string $login,
        bool $superUser,
        Request $httpRequest,
    ): Response {
        $report = $this->report($parameters->idReport ?? 0, $login, $superUser);
        if ($parameters->reportFormat !== null) {
            $report['format'] = $parameters->reportFormat;
        }

        if ($parameters->parameters !== []) {
            $report['parameters'] = $parameters->parameters;
        }

        $html = $this->generator->generate(
            $report,
            $parameters->date ?? 'today',
            $parameters->period ?? (string) ($report['period_param'] ?? 'day'),
            $httpRequest,
        );
        $document = $this->documents->render($html, (string) ($report['format'] ?? 'html'));
        $outputType = $parameters->outputType ?? 1;
        if ($outputType === 2) {
            $path = storage_path('app/scheduled-reports/report-'.$parameters->idReport.'.'.$document->extension);
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
                throw new ScheduledReportException('The report directory could not be created.');
            }

            if (file_put_contents($path, $document->contents, LOCK_EX) === false) {
                throw new ScheduledReportException('The report could not be written.');
            }

            return $this->responses->structured($request, [
                $path,
                $parameters->date ?? 'today',
                (string) ($report['description'] ?? 'Report'),
                (string) ($report['description'] ?? 'Report'),
                [],
            ]);
        }

        if ($outputType === 4) {
            return new Response($document->contents, 200, ['Content-Type' => $document->mimeType]);
        }

        $disposition = $outputType === 3 ? 'inline' : 'attachment';
        $filename = 'scheduled-report-'.$parameters->idReport.'.'.$document->extension;

        return new Response($document->contents, 200, [
            'Content-Type' => $document->mimeType,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }

    private function send(
        ApiRequest $request,
        ScheduledReportsRequest $parameters,
        string $login,
        bool $superUser,
        Request $httpRequest,
    ): Response {
        $report = $this->report($parameters->idReport ?? 0, $login, $superUser);
        $storedParameters = is_array($report['parameters'] ?? null) ? $report['parameters'] : [];
        $html = $this->generator->generate(
            $report,
            $parameters->date ?? 'today',
            $parameters->period ?? (string) ($report['period_param'] ?? 'day'),
            $httpRequest,
        );
        if (($report['type'] ?? null) === 'mobile') {
            $phones = $storedParameters['phoneNumbers'] ?? [];
            if (! is_array($phones) || $phones === []) {
                throw new ScheduledReportException('The scheduled mobile report has no recipients.');
            }

            $this->mobileMessaging->sendMessage(
                (string) ($report['login'] ?? $login),
                trim(strip_tags($html)),
                array_values(array_filter($phones, is_string(...))),
            );
            $this->reports->markSent((int) $report['idreport']);

            return $this->responses->scalar($request, true);
        }

        $recipients = [];
        if (! empty($storedParameters['emailMe'])) {
            $email = $this->users->user((string) ($report['login'] ?? $login))['email'] ?? null;
            if (is_string($email)) {
                $recipients[] = $email;
            }
        }

        $additionalEmails = $storedParameters['additionalEmails'] ?? [];
        foreach (is_array($additionalEmails) ? $additionalEmails : [] as $email) {
            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = $email;
            }
        }

        if ($recipients === []) {
            throw new ScheduledReportException('The scheduled report has no valid recipients.');
        }

        $format = (string) ($report['format'] ?? 'html');
        $attachment = $format === 'pdf' ? $this->documents->render($html, 'pdf') : null;
        $this->sender->send(
            array_values(array_unique($recipients)),
            (string) ($report['description'] ?? 'Report'),
            $html,
            $attachment,
        );
        $this->reports->markSent((int) $report['idreport']);

        return $this->responses->scalar($request, true);
    }

    /** @return array<string, mixed> */
    private function report(int $idReport, string $login, bool $superUser): array
    {
        $report = $this->reports->get(null, null, $idReport, null, $login, $superUser, false)[0];
        if (! $superUser && ($report['login'] ?? null) !== $login) {
            throw new ScheduledReportException("Requested report couldn't be found.");
        }

        return $report;
    }

    /** @return list<string> */
    private function widgetReportIds(string $layout): array
    {
        $decoded = json_decode($layout, true);
        if (! is_array($decoded)) {
            return [];
        }

        $ids = [];
        $walk = static function (array $value) use (&$walk, &$ids): void {
            $module = $value['module'] ?? null;
            $action = $value['action'] ?? null;
            if (is_string($module) && is_string($action)
                && preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $module) === 1
                && preg_match('/^get[A-Za-z0-9]*$/D', $action) === 1) {
                $ids[] = $module.'_'.$action;
            }

            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($decoded);

        return array_values(array_unique($ids));
    }
}
