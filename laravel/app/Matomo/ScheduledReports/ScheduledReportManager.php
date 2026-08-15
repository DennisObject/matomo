<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

final readonly class ScheduledReportManager
{
    public function __construct(private ScheduledReportRepository $reports) {}

    /** @param array<string, mixed> $attributes */
    public function add(string $login, array $attributes): int
    {
        $attributes = $this->validated($attributes);

        return $this->reports->create([
            ...$attributes,
            'login' => $login,
            'ts_created' => gmdate('Y-m-d H:i:s'),
            'ts_last_sent' => null,
            'deleted' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $idReport, string $login, bool $superUser, array $attributes): void
    {
        $this->owned($idReport, $login, $superUser);
        $this->reports->update($idReport, $this->validated($attributes));
    }

    public function delete(int $idReport, string $login, bool $superUser): void
    {
        $this->owned($idReport, $login, $superUser);
        $this->reports->update($idReport, ['deleted' => 1]);
    }

    public function markSent(int $idReport): void
    {
        $this->reports->update($idReport, ['ts_last_sent' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    public function get(
        ?int $idSite,
        ?string $period,
        ?int $idReport,
        ?int $idSegment,
        string $login,
        bool $superUser,
        bool $onlyOwn,
    ): array {
        $this->validatePeriod($period, true);
        $reports = $this->reports->find(
            $idSite,
            $period,
            $idReport,
            ! $superUser || $onlyOwn ? $login : null,
            $idSegment,
        );
        if ($idReport !== null && $reports === []) {
            throw new ScheduledReportException("Requested report couldn't be found.");
        }

        return $reports;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $period = is_string($attributes['period'] ?? null) ? $attributes['period'] : '';
        $periodParam = is_string($attributes['period_param'] ?? null) ? $attributes['period_param'] : null;
        $this->validatePeriod($period, false);
        if ($periodParam !== null && ! in_array($periodParam, ['day', 'week', 'month', 'year'], true)) {
            throw new ScheduledReportException('The report period parameter is invalid.');
        }

        $hour = (int) ($attributes['hour'] ?? -1);
        if ($hour < 0 || $hour > 23) {
            throw new ScheduledReportException('The report hour must be between 0 and 23.');
        }

        $description = trim((string) ($attributes['description'] ?? ''));
        if ($description === '') {
            throw new ScheduledReportException('The report description is required.');
        }

        $type = (string) ($attributes['type'] ?? '');
        $format = (string) ($attributes['format'] ?? '');
        $formats = ['email' => ['html', 'pdf'], 'mobile' => ['sms']];
        if (! isset($formats[$type]) || ! in_array($format, $formats[$type], true)) {
            throw new ScheduledReportException('The report type or format is invalid.');
        }

        $selected = $attributes['reports'] ?? [];
        if (! is_array($selected) || $selected === []) {
            throw new ScheduledReportException('At least one report must be selected.');
        }

        foreach ($selected as $report) {
            if (! is_string($report)
                || preg_match('/^[A-Za-z][A-Za-z0-9]*_get[A-Za-z0-9]*$/D', $report) !== 1) {
                throw new ScheduledReportException('A selected report identifier is invalid.');
            }
        }

        $parameters = $attributes['parameters'] ?? [];
        if (! is_array($parameters)) {
            throw new ScheduledReportException('The report parameters are invalid.');
        }

        if ($type === 'email') {
            $additionalEmails = $parameters['additionalEmails'] ?? [];
            if (! is_array($additionalEmails)) {
                throw new ScheduledReportException('The scheduled report email addresses are invalid.');
            }

            foreach ($additionalEmails as $email) {
                if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new ScheduledReportException('A scheduled report email address is invalid.');
                }
            }
        } elseif (! is_array($parameters['phoneNumbers'] ?? null)
            || array_filter($parameters['phoneNumbers'], static fn (mixed $phone): bool => ! is_string($phone)) !== []) {
            throw new ScheduledReportException('The scheduled mobile report phone numbers are invalid.');
        }

        $evolution = ($attributes['evolution_graph_within_period'] ?? false) ? 1 : 0;

        return [
            ...$attributes,
            'description' => mb_substr($description, 0, 250),
            'period' => $period,
            'period_param' => $periodParam,
            'hour' => $hour,
            'type' => $type,
            'format' => $format,
            'reports' => array_values($selected),
            'parameters' => $parameters,
            'evolution_graph_within_period' => $evolution,
            'evolution_graph_period_n' => max(1, (int) ($attributes['evolution_graph_period_n'] ?? 30)),
        ];
    }

    /** @return array<string, mixed> */
    private function owned(int $idReport, string $login, bool $superUser): array
    {
        $report = $this->reports->find(null, null, $idReport, null, null)[0] ?? null;
        if ($report === null || (! $superUser && ($report['login'] ?? null) !== $login)) {
            throw new ScheduledReportException("Requested report couldn't be found.");
        }

        return $report;
    }

    private function validatePeriod(?string $period, bool $nullable): void
    {
        if ($period === null && $nullable) {
            return;
        }

        if (! in_array($period, ['day', 'week', 'month', 'never'], true)) {
            throw new ScheduledReportException('The report schedule period is invalid.');
        }
    }
}
