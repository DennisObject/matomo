<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Archiving\ActionArchiveConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ActionArchiveConfigurationTest extends TestCase
{
    public function test_loads_global_and_site_specific_action_archive_settings(): void
    {
        $directory = sys_get_temp_dir().'/laravel-action-archive-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('The action archive test directory could not be created.');
        }

        $defaults = $directory.'/defaults.ini';
        $installation = $directory.'/installation.ini';

        try {
            file_put_contents($defaults, <<<'INI'
[General]
action_url_category_delimiter = "/"
action_title_category_delimiter = "::"
datatable_archiving_maximum_rows_actions = 50
datatable_archiving_maximum_rows_subtable_actions = 25
disable_archive_actions_goals = 1

[General_7]
disable_archive_actions_goals = 1
INI);
            file_put_contents($installation, <<<'INI'
[General]
datatable_archiving_maximum_rows_actions = 75
datatable_archiving_maximum_rows_actions_flat = 20
archiving_ranking_query_row_limit = 10
disable_archive_actions_goals = 0

[General_7]
disable_archive_actions_goals = 0

[General_8]
disable_archive_actions_goals = true
INI);

            $configuration = ActionArchiveConfiguration::fromFiles(
                $defaults,
                $installation,
            );

            $this->assertSame('/', $configuration->urlDelimiter);
            $this->assertSame('::', $configuration->titleDelimiter);
            $this->assertSame(75, $configuration->rootLimit);
            $this->assertSame(25, $configuration->subtableLimit);
            $this->assertSame(20, $configuration->flatLimit);
            $this->assertSame(75, $configuration->rankingLimit);
            $this->assertFalse($configuration->goalsDisabled(1));
            $this->assertFalse($configuration->goalsDisabled(7));
            $this->assertTrue($configuration->goalsDisabled(8));
        } finally {
            if (is_file($defaults)) {
                unlink($defaults);
            }

            if (is_file($installation)) {
                unlink($installation);
            }

            rmdir($directory);
        }
    }
}
