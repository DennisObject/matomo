<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Insights\CoreInsightReportReader;
use App\Matomo\Referrers\ReferrerDefinitionCatalog;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use Tests\TestCase;

class ReferrerInsightFallbackTest extends TestCase
{
    public function test_recovers_and_groups_social_rows_from_legacy_website_archives(): void
    {
        $period = $this->period();
        $blobs = $this->createMock(BlobArchiveRepository::class);
        $blobs->expects($this->exactly(2))
            ->method('rows')
            ->willReturnOnConsecutiveCalls(
                [],
                [7 => [$period->rangeKey() => [
                    $this->archiveRow('m.facebook.com', 2),
                    $this->archiveRow('facebook.com', 3),
                    $this->archiveRow('example.com', 7),
                ]]],
            );
        $definitions = $this->createStub(ReferrerDefinitionCatalog::class);
        $definitions->method('socialName')->willReturnCallback(
            static fn (string $url): ?string => str_contains($url, 'facebook.com') ? 'Facebook' : null,
        );
        $this->app->instance(BlobArchiveRepository::class, $blobs);
        $this->app->instance(ReferrerDefinitionCatalog::class, $definitions);

        $rows = $this->app->make(CoreInsightReportReader::class)->referrers(
            'Referrers_urlBySocialNetwork',
            7,
            $period,
            'segment-hash',
        );

        $this->assertSame([['label' => 'Facebook', 'nb_visits' => 5]], $rows);
    }

    public function test_groups_legacy_lowercase_instagram_rows_in_primary_archives(): void
    {
        $period = $this->period();
        $blobs = $this->createStub(BlobArchiveRepository::class);
        $blobs->method('rows')->willReturn([7 => [$period->rangeKey() => [
            $this->archiveRow('Instagram', 2),
            $this->archiveRow('instagram', 3),
        ]]]);
        $this->app->instance(BlobArchiveRepository::class, $blobs);

        $rows = $this->app->make(CoreInsightReportReader::class)->referrers(
            'Referrers_urlBySocialNetwork',
            7,
            $period,
            'segment-hash',
        );

        $this->assertSame([['label' => 'Instagram', 'nb_visits' => 5]], $rows);
    }

    /**
     * @return array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}
     */
    private function archiveRow(string $label, int $visits): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits],
            'metadata' => [],
        ];
    }

    private function period(): ReportingPeriod
    {
        return new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
    }
}
