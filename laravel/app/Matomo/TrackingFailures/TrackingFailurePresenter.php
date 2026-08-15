<?php

declare(strict_types=1);

namespace App\Matomo\TrackingFailures;

use App\Matomo\Localization\LocalizedDateTimeFormatter;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\TrackingFailures\Events\TrackingFailuresMakingHumanReadable;
use App\Support\MatomoProductUrl;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class TrackingFailurePresenter
{
    public function __construct(
        private SiteRepository $sites,
        private MatomoTranslator $translator,
        private LocalizedDateTimeFormatter $dates,
        private Dispatcher $events,
    ) {}

    /**
     * @param  list<array<string, int|string>>  $failures
     * @return list<array<string, mixed>>
     */
    public function present(array $failures, string $language, bool $uses12HourClock): array
    {
        $rows = [];

        foreach ($failures as $failure) {
            $siteId = (int) ($failure['idsite'] ?? 0);
            $failureId = (int) ($failure['idfailure'] ?? 0);
            $site = $this->sites->details($siteId);
            $requestUrl = $failure['request_url'] ?? '';
            $parameters = [];

            if (is_string($requestUrl)) {
                parse_str($requestUrl, $parameters);
            }

            $url = $parameters['url'] ?? '';
            $date = $failure['date_first_occurred'] ?? '';
            $row = [
                ...$failure,
                'site_name' => is_string($site['name'] ?? null)
                    ? $site['name']
                    : $this->translator->translate('General_Unknown', $language),
                'pretty_date_first_occurred' => is_string($date)
                    ? $this->dates->short($date, $language, $uses12HourClock)
                    : '',
                'url' => is_string($url) ? trim($url) : '',
                'problem' => '',
                'solution' => '',
                'solution_url' => '',
            ];

            if ($failureId === 1) {
                $row['problem'] = $this->translator->translate(
                    'CoreAdminHome_TrackingFailureInvalidSiteProblem',
                    $language,
                );
                $row['solution'] = $this->translator->translate(
                    'CoreAdminHome_TrackingFailureInvalidSiteSolution',
                    $language,
                );
                $row['solution_url'] = MatomoProductUrl::https('faq/how-to/faq_30838/');
            } elseif ($failureId === 2) {
                $row['problem'] = $this->translator->translate(
                    'CoreAdminHome_TrackingFailureAuthenticationProblem',
                    $language,
                );
                $row['solution'] = $this->translator->translate(
                    'CoreAdminHome_TrackingFailureAuthenticationSolution',
                    $language,
                );
                $row['solution_url'] = MatomoProductUrl::https('faq/how-to/faq_30835/');
            }

            $rows[] = $row;
        }

        $event = new TrackingFailuresMakingHumanReadable($rows);
        $this->events->dispatch($event);

        return $event->failures;
    }
}
