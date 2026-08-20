<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Plugins\LocalTrackerFileAvailability;
use PHPUnit\Framework\TestCase;

class LocalTrackerFileAvailabilityTest extends TestCase
{
    public function test_accepts_readable_source_and_writable_target_directory(): void
    {
        $directory = '/dev/shm/matomo-tracker-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $source = $directory.'/piwik.min.js';
        file_put_contents($source, 'tracker');

        try {
            $this->assertTrue((new LocalTrackerFileAvailability($source, $directory.'/matomo.js'))->canUpdate());
        } finally {
            unlink($source);
            rmdir($directory);
        }
    }

    public function test_rejects_missing_source(): void
    {
        $this->assertFalse((new LocalTrackerFileAvailability('/missing/source.js', '/missing/target.js'))->canUpdate());
    }
}
