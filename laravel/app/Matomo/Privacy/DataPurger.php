<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface DataPurger
{
    public function purge(): void;
}
