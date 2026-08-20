<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiMetricReport;
use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiTableReport;
use Carbon\CarbonImmutable;

final class RssReportRenderer
{
    /**
     * @param  list<ReportingPeriod>  $periods
     */
    public function report(
        ApiReport $report,
        array $periods,
        int $idSite,
        string $period,
        string $siteName,
        string $timezone,
    ): string {
        return $this->render(
            $report->data,
            $report->dimensions,
            $periods,
            $idSite,
            $period,
            $siteName,
            $timezone,
            'report',
        );
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     */
    public function metric(
        ApiMetricReport $report,
        array $periods,
        int $idSite,
        string $period,
        string $siteName,
        string $timezone,
    ): string {
        return $this->render(
            $report->data,
            $report->dimensions,
            $periods,
            $idSite,
            $period,
            $siteName,
            $timezone,
            'metric',
        );
    }

    /** @param list<ReportingPeriod> $periods */
    public function table(
        ApiTableReport $report,
        array $periods,
        int $idSite,
        string $period,
        string $siteName,
        string $timezone,
    ): string {
        return $this->render(
            $report->data,
            $report->dimensions,
            $periods,
            $idSite,
            $period,
            $siteName,
            $timezone,
            'table',
        );
    }

    /**
     * @param  array<array-key, mixed>|float|int|string|null  $data
     * @param  list<'idSite'|'date'>  $dimensions
     * @param  list<ReportingPeriod>  $periods
     */
    private function render(
        array|float|int|string|null $data,
        array $dimensions,
        array $periods,
        int $idSite,
        string $period,
        string $siteName,
        string $timezone,
        string $mode,
    ): string {
        if ($dimensions !== ['date'] || ! is_array($data)) {
            throw new \InvalidArgumentException(
                "RSS feeds can be generated for one specific website &idSite=X.\n".
                'Please specify only one idSite or consider using &format=XML instead.',
            );
        }

        $periodsByKey = [];

        foreach ($periods as $reportingPeriod) {
            $periodsByKey[$reportingPeriod->resultKey] = $reportingPeriod;
        }

        $now = CarbonImmutable::now('UTC')->format('r');
        $applicationUrl = rtrim((string) config('app.url'), '/');
        $items = '';

        foreach (array_reverse($data, true) as $date => $row) {
            $date = (string) $date;
            $reportingPeriod = $periodsByKey[$date] ?? null;

            if ($reportingPeriod === null) {
                continue;
            }

            $published = CarbonImmutable::parse($reportingPeriod->startDate, $timezone)
                ->utc()->format('r');
            $url = $applicationUrl.'/index.php?'.http_build_query([
                'module' => 'CoreHome',
                'action' => 'index',
                'idSite' => $idSite,
                'period' => $period,
                'date' => $reportingPeriod->startDate,
            ], '', '&', PHP_QUERY_RFC3986);
            $title = $siteName.' on '.$date;
            $description = $this->tableContent($row, $mode);
            $items .= "\t<item>\n".
                "\t\t<pubDate>{$this->escape($published)}</pubDate>\n".
                "\t\t<guid>{$this->escape($url)}</guid>\n".
                "\t\t<link>{$this->escape($url)}</link>\n".
                "\t\t<title>{$this->escape($title)}</title>\n".
                "\t\t<author>{$this->escape($applicationUrl)}</author>\n".
                "\t\t<description>{$this->escape($description)}</description>\n".
                "\t</item>\n";
        }

        $link = $this->escape($applicationUrl);

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<rss version=\"2.0\">\n".
            "  <channel>\n".
            "    <title>matomo statistics - RSS</title>\n".
            "    <link>{$link}</link>\n".
            "    <description>Matomo RSS feed</description>\n".
            "    <pubDate>{$now}</pubDate>\n".
            "    <generator>matomo</generator>\n".
            "    <language>en</language>\n".
            "    <lastBuildDate>{$now}</lastBuildDate>\n".
            $items.
            "\t</channel>\n".
            '</rss>';
    }

    private function tableContent(mixed $row, string $mode): string
    {
        if ($mode === 'metric') {
            return $this->htmlTable(['0' => $this->scalar($row)]);
        }

        if (! is_array($row) || $row === []) {
            return "<strong><em>Empty table</em></strong><br />\n";
        }

        if ($mode === 'table') {
            return $this->htmlRows($row);
        }

        $values = [];

        foreach ($row as $name => $value) {
            if (is_float($value) || is_int($value) || is_string($value) || $value === null) {
                $values[(string) $name] = $value;
            }
        }

        return $this->htmlTable($values);
    }

    /** @param array<array-key, mixed> $rows */
    private function htmlRows(array $rows): string
    {
        $values = [];
        $columns = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $valuesRow = [];

            foreach ($row as $name => $value) {
                if (is_string($name)
                    && (is_float($value) || is_int($value) || is_string($value) || $value === null)) {
                    $valuesRow[$name] = $value;

                    if (! in_array($name, $columns, true)) {
                        $columns[] = $name;
                    }
                }
            }

            $values[] = $valuesRow;
        }

        if ($values === []) {
            return "<strong><em>Empty table</em></strong><br />\n";
        }

        $html = "\n<table border=1 width=70%>\n<tr>";

        foreach ($columns as $column) {
            $html .= "\n\t<td><strong>{$this->escape($column)}</strong></td>";
        }

        $html .= "\n</tr>";

        foreach ($values as $row) {
            $html .= "\n\n<tr>";

            foreach ($columns as $column) {
                $html .= "\n\t<td>{$this->escape(array_key_exists($column, $row) ? $this->scalar($row[$column]) : '-')}</td>";
            }

            $html .= '</tr>';
        }

        return $html."\n\n</table>";
    }

    /** @param array<array-key, float|int|string|null> $values */
    private function htmlTable(array $values): string
    {
        if ($values === []) {
            return "<strong><em>Empty table</em></strong><br />\n";
        }

        $headers = '';
        $cells = '';

        foreach ($values as $name => $value) {
            $headers .= "\n\t<td><strong>{$this->escape((string) $name)}</strong></td>";
            $cells .= "\n\t<td>{$this->escape($this->scalar($value))}</td>";
        }

        return "\n<table border=1 width=70%>\n<tr>{$headers}\n</tr>\n\n<tr>{$cells}</tr>\n\n</table>";
    }

    private function scalar(mixed $value): string
    {
        return is_float($value) || is_int($value) || is_string($value) ? (string) $value : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
