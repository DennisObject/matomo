<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use InvalidArgumentException;

final class SegmentDefinitionValidator
{
    public function validate(?string $segment): void
    {
        if ($segment === null || $segment === '') {
            return;
        }

        foreach (array_values(array_unique([$segment, urldecode($segment)])) as $candidate) {
            if ($this->hasValidSyntax($candidate)) {
                return;
            }
        }

        throw new InvalidArgumentException("The segment condition '{$segment}' is not valid.");
    }

    private function hasValidSyntax(string $segment): bool
    {
        $andExpressions = preg_split('/;(?!$)(?<!\\\\;)/', trim($segment));

        if (! is_array($andExpressions)) {
            return false;
        }

        $andExpressions = array_values(array_filter(
            $andExpressions,
            static fn (string $expression): bool => $expression !== '',
        ));

        if ($andExpressions === []) {
            return false;
        }

        foreach ($andExpressions as $andExpression) {
            $orExpressions = preg_split('/,(?!$)(?<!\\\\,)/', $andExpression);

            if (! is_array($orExpressions)) {
                return false;
            }

            foreach ($orExpressions as $operand) {
                $operand = str_replace(['\\;', '\\,'], [';', ','], urldecode($operand));

                if (preg_match('/^(.+?)(==|!=|>=|<=|=@|!@|=\^|=\$|>|<)(.*)$/s', $operand, $parts) !== 1
                    || ($parts[3] === '' && ! in_array($parts[2], ['==', '!='], true))) {
                    return false;
                }
            }
        }

        return true;
    }
}
