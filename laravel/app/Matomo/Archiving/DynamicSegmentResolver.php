<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class DynamicSegmentResolver
{
    public function resolve(
        Builder $query,
        string $name,
        ?int $siteId,
    ): ?DynamicSegmentDefinition {
        $customVariable = $this->customVariable($name);

        if ($customVariable !== null) {
            return $customVariable;
        }

        if ($siteId === null
            || preg_match('/^dimension([1-9][0-9]*)$/D', $name, $matches) !== 1) {
            return null;
        }

        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            return null;
        }

        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('custom_dimensions')) {
            return null;
        }

        $dimensionId = (int) $matches[1];
        $configuration = $connection
            ->table('custom_dimensions')
            ->where('idcustomdimension', $dimensionId)
            ->where('idsite', $siteId)
            ->where('active', 1)
            ->first(['index', 'scope']);

        if ($configuration === null
            || ! is_numeric($configuration->index ?? null)
            || ! is_string($configuration->scope ?? null)) {
            return null;
        }

        $index = (int) $configuration->index;

        if ($index < 1) {
            return null;
        }

        $scope = $configuration->scope;
        $table = match ($scope) {
            'action' => 'log_link_visit_action',
            'conversion' => 'log_conversion',
            'visit' => 'log_visit',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        $column = "custom_dimension_{$index}";

        if (! $schema->hasColumn($table, $column)) {
            return null;
        }

        $alias = match ($scope) {
            'action' => 'segment_action',
            'conversion' => 'segment_conversion',
            'visit' => 'log_visit',
        };

        return new DynamicSegmentDefinition($scope, ["{$alias}.{$column}"]);
    }

    private function customVariable(string $name): ?DynamicSegmentDefinition
    {
        if (preg_match(
            '/^customVariable(Page)?(Name|Value)([1-5])?$/D',
            $name,
            $matches,
        ) !== 1) {
            return null;
        }

        $scope = $matches[1] === 'Page' ? 'action' : 'visit';
        $columnType = $matches[2] === 'Name' ? 'k' : 'v';
        $slot = $matches[3] ?? '';
        $slots = $slot === '' ? range(1, 5) : [(int) $slot];
        $alias = $scope === 'action' ? 'segment_action' : 'log_visit';
        $expressions = [];

        foreach ($slots as $index) {
            $expressions[] = "{$alias}.custom_var_{$columnType}{$index}";
        }

        return new DynamicSegmentDefinition($scope, $expressions);
    }
}
