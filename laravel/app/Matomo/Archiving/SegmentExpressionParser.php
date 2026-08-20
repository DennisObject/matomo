<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use InvalidArgumentException;

final class SegmentExpressionParser
{
    /**
     * @return list<list<SegmentCondition>>
     */
    public function parse(?string $segment): array
    {
        if ($segment === null || $segment === '') {
            return [];
        }

        foreach (array_values(array_unique([urldecode($segment), $segment])) as $candidate) {
            $conditions = $this->parseCandidate($candidate);

            if ($conditions !== null) {
                return $conditions;
            }
        }

        throw new InvalidArgumentException("The segment condition '{$segment}' is not valid.");
    }

    /**
     * @return list<list<SegmentCondition>>|null
     */
    private function parseCandidate(string $segment): ?array
    {
        $andExpressions = preg_split('/;(?!$)(?<!\\\\;)/', trim($segment));

        if (! is_array($andExpressions)) {
            return null;
        }

        $andExpressions = array_values(array_filter(
            $andExpressions,
            static fn (string $expression): bool => $expression !== '',
        ));

        if ($andExpressions === []) {
            return null;
        }

        $conditions = [];

        foreach ($andExpressions as $andExpression) {
            $orExpressions = preg_split('/,(?!$)(?<!\\\\,)/', $andExpression);

            if (! is_array($orExpressions)) {
                return null;
            }

            $group = [];

            foreach ($orExpressions as $operand) {
                $operand = str_replace(['\\;', '\\,'], [';', ','], urldecode($operand));

                if (preg_match('/^(.+?)(==|!=|>=|<=|=@|!@|=\^|=\$|>|<)(.*)$/s', $operand, $parts) !== 1
                    || ($parts[3] === '' && ! in_array($parts[2], ['==', '!='], true))) {
                    return null;
                }

                $group[] = new SegmentCondition($parts[1], $parts[2], urldecode($parts[3]));
            }

            $conditions[] = $group;
        }

        return $conditions;
    }
}
