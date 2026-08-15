<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Goals\FileSiteTrackerCacheInvalidator;
use PHPUnit\Framework\TestCase;

class FileSiteTrackerCacheInvalidatorTest extends TestCase
{
    public function test_clears_only_the_requested_site_cache_file(): void
    {
        $directory = sys_get_temp_dir().'/matomo-goal-cache-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory));
        $siteCache = $directory.'/matomocache_7.php';
        $otherCache = $directory.'/matomocache_8.php';
        $this->assertNotFalse(file_put_contents($siteCache, 'site 7'));
        $this->assertNotFalse(file_put_contents($otherCache, 'site 8'));

        try {
            (new FileSiteTrackerCacheInvalidator($directory))->clear(7);

            $this->assertFileDoesNotExist($siteCache);
            $this->assertFileExists($otherCache);
        } finally {
            if (is_file($siteCache)) {
                unlink($siteCache);
            }

            if (is_file($otherCache)) {
                unlink($otherCache);
            }

            rmdir($directory);
        }
    }
}
