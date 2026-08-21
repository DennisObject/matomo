<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Matomo\Archiving\CronArchiveRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('core:archive')]
#[Description('Archive Matomo reports.')]
final class CoreArchiveCommand extends Command
{
    public function handle(CronArchiveRunner $runner): int
    {
        $result = $runner->run();

        foreach ($result->lines as $line) {
            $this->line($line);
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
