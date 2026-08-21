<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Options\OptionRepository;

final class ReferrerSpamList
{
    /** @var list<string>|null */
    private ?array $hosts = null;

    public function __construct(
        private readonly OptionRepository $options,
        private readonly string $bundledListPath,
    ) {}

    public function matches(string $referrerUrl): bool
    {
        if ($referrerUrl === '') {
            return false;
        }

        foreach ($this->hosts() as $host) {
            if ($host !== '' && stripos($referrerUrl, $host) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }

        $stored = $this->options->value('referrer_spam_blacklist');
        if ($stored !== null && $stored !== '') {
            $list = unserialize($stored, ['allowed_classes' => false]);
            if (is_array($list)) {
                return $this->hosts = array_values(array_filter(
                    $list,
                    static fn (mixed $host): bool => is_string($host) && $host !== '',
                ));
            }
        }

        if (! is_file($this->bundledListPath) || ! is_readable($this->bundledListPath)) {
            return $this->hosts = [];
        }

        $lines = file($this->bundledListPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $this->hosts = is_array($lines) ? $lines : [];
    }
}
