<?php

declare(strict_types=1);

namespace App\Matomo\Options;

interface MutableOptionRepository extends OptionRepository
{
    public function set(string $name, string $value, bool $autoload = false): void;

    public function delete(string $name): void;
}
