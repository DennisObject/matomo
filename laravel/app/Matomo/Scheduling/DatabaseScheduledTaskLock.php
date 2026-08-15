<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

final class DatabaseScheduledTaskLock implements ScheduledTaskLock
{
    private ?string $key = null;

    private ?string $value = null;

    public function __construct(private readonly Connection $connection) {}

    public function acquire(string $taskName, int $ttl): bool
    {
        if ($ttl === -1) {
            $this->key = null;
            $this->value = null;

            return true;
        }

        $key = $this->key($taskName);
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $this->connection->table('locks')
            ->where('key', $key)
            ->where('expiry_time', '<', $now)
            ->delete();
        $value = bin2hex(random_bytes(6));

        try {
            $this->connection->table('locks')->insert([
                'key' => $key,
                'value' => $value,
                'expiry_time' => $now + $ttl,
            ]);
        } catch (QueryException $queryException) {
            if ($this->isDuplicateKey($queryException)) {
                return false;
            }

            throw $queryException;
        }

        $acquired = $this->connection->table('locks')
            ->where('key', $key)
            ->where('value', $value)
            ->exists();

        if ($acquired) {
            $this->key = $key;
            $this->value = $value;
        }

        return $acquired;
    }

    public function release(): void
    {
        if ($this->key !== null && $this->value !== null) {
            $this->connection->table('locks')
                ->where('key', $this->key)
                ->where('value', $this->value)
                ->delete();
        }

        $this->key = null;
        $this->value = null;
    }

    private function key(string $taskName): string
    {
        $key = 'ScheduledTask'.$taskName;

        return mb_strlen($key) <= 70
            ? $key
            : mb_substr($key, 0, 37).md5($taskName);
    }

    private function isDuplicateKey(QueryException $queryException): bool
    {
        return in_array((string) $queryException->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($queryException->getMessage()), 'duplicate')
            || str_contains(strtolower($queryException->getMessage()), 'unique constraint');
    }
}
