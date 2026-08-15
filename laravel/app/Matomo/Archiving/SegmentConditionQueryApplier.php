<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Geolocation\CountryMetadataProvider;
use DeviceDetector\Parser\Client\Browser;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DeviceDetector\Parser\OperatingSystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use InvalidArgumentException;

final readonly class SegmentConditionQueryApplier
{
    /** @var array<string, int> */
    private const array VISITOR_TYPES = [
        'new' => 0,
        'returning' => 1,
        'returningCustomer' => 2,
    ];

    /** @var array<string, int> */
    private const array ECOMMERCE_STATUSES = [
        'none' => 0,
        'ordered' => 1,
        'abandonedCart' => 2,
        'orderedThenAbandonedCart' => 3,
    ];

    /** @var array<string, int> */
    private const array REFERRER_TYPES = [
        'direct' => 1,
        'search' => 2,
        'website' => 3,
        'campaign' => 6,
        'social' => 7,
        'ai' => 8,
    ];

    /** @var array<string, int> */
    private const array ACTION_TYPES = [
        'pageviews' => 1,
        'contents' => 13,
        'sitesearches' => 8,
        'events' => 10,
        'outlinks' => 2,
        'downloads' => 3,
    ];

    /** @var array<string, list<string>> */
    private array $countriesByContinent;

    /** @var array<string, string> */
    private array $countryCodesByName;

    public function __construct(CountryMetadataProvider $countries)
    {
        $countriesByContinent = [];
        $countryCodesByName = [];

        foreach ($countries->codes() as $country) {
            $continent = $countries->continentCode($country);
            $countriesByContinent[$continent][] = $country;
            $countryCodesByName[mb_strtolower($countries->countryName($country, 'en'))] = $country;
        }

        $this->countriesByContinent = $countriesByContinent;
        $this->countryCodesByName = $countryCodesByName;
    }

    /**
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  literal-string  $type
     */
    public function apply(
        Builder $query,
        SegmentCondition $condition,
        string $expression,
        string $type,
    ): void {
        if ($condition->value !== '' && $type === 'continent') {
            $this->applyContinentCondition($query, $condition, $expression);

            return;
        }

        if (preg_match('/^(?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*$/D', $expression) === 1) {
            $this->applyQualifiedCondition($query, $condition, $expression, $type);

            return;
        }

        if ($condition->value === '') {
            $this->applyEmptyCondition($query, $condition->operator, $expression);

            return;
        }

        $value = $this->filterValue($condition->value, $type, $condition->name);
        $numeric = in_array($type, [
            'number',
            'boolean',
            'visitor-type',
            'ecommerce-status',
            'referrer-type',
            'device-type',
            'action-type',
        ], true);
        $alsoMatchesNull = ! in_array($value, ['', '0', 0, 0.0, false, null], true);

        match ($condition->operator) {
            '==' => $this->whereComparison($query, $expression, '=', $value, $numeric),
            '!=' => $this->whereNegativeComparison(
                $query,
                $expression,
                $value,
                $numeric,
                $alsoMatchesNull,
            ),
            '>' => $this->whereComparison($query, $expression, '>', $value, $numeric),
            '<' => $this->whereComparison($query, $expression, '<', $value, $numeric),
            '>=' => $this->whereComparison($query, $expression, '>=', $value, $numeric),
            '<=' => $this->whereComparison($query, $expression, '<=', $value, $numeric),
            '=@' => $this->whereLike($query, $expression, $value, 'contains', false),
            '!@' => $this->whereNotContains(
                $query,
                $expression,
                $value,
                $alsoMatchesNull,
            ),
            '=^' => $this->whereLike($query, $expression, $value, 'starts', false),
            '=$' => $this->whereLike($query, $expression, $value, 'ends', false),
            default => throw new InvalidArgumentException(
                "The segment operator '{$condition->operator}' is not supported.",
            ),
        };
    }

    /** @param string $expression Fixed registry or validated dynamic column. */
    private function applyEmptyCondition(Builder $query, string $operator, string $expression): void
    {
        if ($operator === '==') {
            $query->where(function (Builder $empty) use ($expression): void {
                $empty->whereRaw(new TrustedSegmentSqlExpression("{$expression} IS NULL"))
                    ->whereRaw(
                        new TrustedSegmentSqlExpression("{$expression} = ''"),
                        [],
                        'or',
                    )
                    ->whereRaw(
                        new TrustedSegmentSqlExpression("{$expression} = '0'"),
                        [],
                        'or',
                    );
            });

            return;
        }

        if ($operator === '!=') {
            $query->whereRaw(new TrustedSegmentSqlExpression("{$expression} IS NOT NULL"))
                ->whereRaw(new TrustedSegmentSqlExpression("{$expression} <> ''"))
                ->whereRaw(new TrustedSegmentSqlExpression("{$expression} <> '0'"));

            return;
        }

        throw new InvalidArgumentException("The segment operator '{$operator}' requires a value.");
    }

    /**
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  literal-string  $type
     */
    private function applyQualifiedCondition(
        Builder $query,
        SegmentCondition $condition,
        string $expression,
        string $type,
    ): void {
        if ($condition->value === '') {
            if ($condition->operator === '==') {
                $query->where(function (Builder $empty) use ($expression): void {
                    $empty->whereNull($expression)
                        ->orWhere($expression, '')
                        ->orWhere($expression, '0');
                });

                return;
            }

            if ($condition->operator === '!=') {
                $query->whereNotNull($expression)
                    ->where($expression, '<>', '')
                    ->where($expression, '<>', '0');

                return;
            }

            throw new InvalidArgumentException(
                "The segment operator '{$condition->operator}' requires a value.",
            );
        }

        $value = $this->filterValue($condition->value, $type, $condition->name);
        $alsoMatchesNull = ! in_array($value, ['', '0', 0, 0.0, false, null], true);

        match ($condition->operator) {
            '==' => $query->where($expression, '=', $value),
            '!=' => $this->whereQualifiedNegativeComparison(
                $query,
                $expression,
                $value,
                $alsoMatchesNull,
            ),
            '>' => $query->where($expression, '>', $value),
            '<' => $query->where($expression, '<', $value),
            '>=' => $query->where($expression, '>=', $value),
            '<=' => $query->where($expression, '<=', $value),
            '=@' => $this->whereQualifiedLike($query, $expression, $value, 'contains', false),
            '!@' => $this->whereQualifiedNotContains(
                $query,
                $expression,
                $value,
                $alsoMatchesNull,
            ),
            '=^' => $this->whereQualifiedLike($query, $expression, $value, 'starts', false),
            '=$' => $this->whereQualifiedLike($query, $expression, $value, 'ends', false),
            default => throw new InvalidArgumentException(
                "The segment operator '{$condition->operator}' is not supported.",
            ),
        };
    }

    /** @param string $expression Fixed registry or validated dynamic column. */
    private function whereQualifiedNegativeComparison(
        Builder $query,
        string $expression,
        mixed $value,
        bool $alsoMatchesNull,
    ): void {
        if (! $alsoMatchesNull) {
            $query->where($expression, '<>', $value);

            return;
        }

        $query->where(function (Builder $notEqual) use ($expression, $value): void {
            $notEqual->whereNull($expression)->orWhere($expression, '<>', $value);
        });
    }

    /** @param string $expression Fixed registry or validated dynamic column. */
    private function whereQualifiedNotContains(
        Builder $query,
        string $expression,
        mixed $value,
        bool $alsoMatchesNull,
    ): void {
        if (! $alsoMatchesNull) {
            $this->whereQualifiedLike($query, $expression, $value, 'contains', true);

            return;
        }

        $query->where(function (Builder $notContains) use ($expression, $value): void {
            $notContains->whereNull($expression)
                ->orWhere(function (Builder $present) use ($expression, $value): void {
                    $this->whereQualifiedLike($present, $expression, $value, 'contains', true);
                });
        });
    }

    /**
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  'contains'|'ends'|'starts'  $position
     */
    private function whereQualifiedLike(
        Builder $query,
        string $expression,
        mixed $value,
        string $position,
        bool $negated,
    ): void {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
        $pattern = match ($position) {
            'contains' => "%{$escaped}%",
            'starts' => "{$escaped}%",
            'ends' => "%{$escaped}",
        };
        $operator = $negated ? 'NOT LIKE' : 'LIKE';
        $escape = $query->getGrammar() instanceof SQLiteGrammar
            ? " ESCAPE '\\'"
            : " ESCAPE '\\\\'";
        $column = $query->getGrammar()->wrap($expression);
        $query->whereRaw(
            new TrustedSegmentSqlExpression("{$column} {$operator} ?{$escape}"),
            [$pattern],
        );
    }

    /** @param string $expression Fixed registry or validated dynamic column. */
    private function applyContinentCondition(
        Builder $query,
        SegmentCondition $condition,
        string $expression,
    ): void {
        $continent = strtolower(trim($condition->value));
        $countries = $this->countriesByContinent[$continent] ?? [];

        if ($countries === []) {
            throw new InvalidArgumentException(
                "The 'continentCode' segment value '{$condition->value}' is not valid.",
            );
        }

        if ($condition->operator === '==') {
            $query->whereIn($expression, $countries);

            return;
        }

        if ($condition->operator === '!=') {
            $query->where(function (Builder $notInContinent) use ($expression, $countries): void {
                $notInContinent->whereNull($expression)->orWhereNotIn($expression, $countries);
            });

            return;
        }

        throw new InvalidArgumentException(
            "The 'continentCode' segment only supports the == and != operators.",
        );
    }

    /**
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  '<'|'<='|'<>'|'='|'>'|'>='  $operator
     */
    private function whereComparison(
        Builder $query,
        string $expression,
        string $operator,
        mixed $value,
        bool $numeric,
    ): void {
        $placeholder = $numeric ? 'CAST(? AS DECIMAL(65, 20))' : '?';
        $query->whereRaw(
            new TrustedSegmentSqlExpression("{$expression} {$operator} {$placeholder}"),
            [$value],
        );
    }

    /** @param string $expression Fixed registry or validated dynamic column. */
    private function whereNegativeComparison(
        Builder $query,
        string $expression,
        mixed $value,
        bool $numeric,
        bool $alsoMatchesNull,
    ): void {
        if (! $alsoMatchesNull) {
            $this->whereComparison($query, $expression, '<>', $value, $numeric);

            return;
        }

        $query->where(function (Builder $notEqual) use ($expression, $value, $numeric): void {
            $notEqual->whereRaw(new TrustedSegmentSqlExpression("{$expression} IS NULL"))
                ->orWhere(function (Builder $present) use ($expression, $value, $numeric): void {
                    $this->whereComparison($present, $expression, '<>', $value, $numeric);
                });
        });
    }

    /** @param string $expression Fixed registry or validated dynamic column. */
    private function whereNotContains(
        Builder $query,
        string $expression,
        mixed $value,
        bool $alsoMatchesNull,
    ): void {
        if (! $alsoMatchesNull) {
            $this->whereLike($query, $expression, $value, 'contains', true);

            return;
        }

        $query->where(function (Builder $notContains) use ($expression, $value): void {
            $notContains->whereRaw(new TrustedSegmentSqlExpression("{$expression} IS NULL"))
                ->orWhere(function (Builder $present) use ($expression, $value): void {
                    $this->whereLike($present, $expression, $value, 'contains', true);
                });
        });
    }

    /**
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  'contains'|'ends'|'starts'  $position
     */
    private function whereLike(
        Builder $query,
        string $expression,
        mixed $value,
        string $position,
        bool $negated,
    ): void {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
        $pattern = match ($position) {
            'contains' => "%{$escaped}%",
            'starts' => "{$escaped}%",
            'ends' => "%{$escaped}",
        };
        $operator = $negated ? 'NOT LIKE' : 'LIKE';
        $escape = $query->getGrammar() instanceof SQLiteGrammar
            ? " ESCAPE '\\'"
            : " ESCAPE '\\\\'";
        $query->whereRaw(
            new TrustedSegmentSqlExpression("{$expression} {$operator} ?{$escape}"),
            [$pattern],
        );
    }

    private function filterValue(string $value, string $type, string $name): mixed
    {
        return match ($type) {
            'text' => $value,
            'browser-name' => $this->namedCode($value, Browser::getAvailableBrowsers()),
            'os-name' => $this->namedCode($value, OperatingSystem::getAvailableOperatingSystems()),
            'country-name' => $this->countryCodesByName[mb_strtolower($value)] ?? 'UNK',
            'number' => $this->numericValue($value, $name),
            'boolean' => match ($value) {
                '0' => 0,
                '1' => 1,
                default => throw new InvalidArgumentException(
                    "The '{$name}' segment value '{$value}' is not valid.",
                ),
            },
            'visitor-id' => $this->visitorId($value, $name),
            'ip' => $this->ip($value, $name),
            'visitor-type' => is_numeric($value)
                ? $this->numericValue($value, $name)
                : self::VISITOR_TYPES[$value] ?? throw new InvalidArgumentException(
                    "The '{$name}' segment value '{$value}' is not valid.",
                ),
            'ecommerce-status' => $this->enumValue($value, self::ECOMMERCE_STATUSES, $name),
            'referrer-type' => $this->enumValue($value, self::REFERRER_TYPES, $name),
            'device-type' => $this->deviceType($value, $name),
            'action-type' => $this->enumValue($value, self::ACTION_TYPES, $name),
            default => throw new InvalidArgumentException("The '{$name}' segment type '{$type}' is not valid."),
        };
    }

    /** @param array<string, string> $names */
    private function namedCode(string $value, array $names): string
    {
        $normalized = mb_strtolower($value);

        foreach ($names as $code => $name) {
            if (mb_strtolower($name) === $normalized) {
                return $code;
            }
        }

        return 'UNK';
    }

    private function numericValue(string $value, string $name): string
    {
        if (preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value) !== 1) {
            throw new InvalidArgumentException("The '{$name}' segment value '{$value}' must be numeric.");
        }

        return $value;
    }

    /** @param array<string, int> $values */
    private function enumValue(string $value, array $values, string $name): int
    {
        if (array_key_exists($value, $values)) {
            return $values[$value];
        }

        if (ctype_digit($value) && in_array((int) $value, $values, true)) {
            return (int) $value;
        }

        $normalized = strtolower(trim($value));

        foreach ($values as $label => $id) {
            if (strtolower($label) === $normalized) {
                return $id;
            }
        }

        throw new InvalidArgumentException("The '{$name}' segment value '{$value}' is not valid.");
    }

    private function deviceType(string $value, string $name): int
    {
        /** @var array<string, int> $types */
        $types = AbstractDeviceParser::getAvailableDeviceTypes();

        return $this->enumValue($value, $types, $name);
    }

    private function visitorId(string $value, string $name): string
    {
        if (preg_match('/^[0-9a-f]{16}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                "The '{$name}' segment value must be a 16 character lower-case hexadecimal ID.",
            );
        }

        $binary = hex2bin($value);

        if ($binary === false) {
            throw new InvalidArgumentException("The '{$name}' segment value is not valid hexadecimal.");
        }

        return $binary;
    }

    private function ip(string $value, string $name): string
    {
        $binary = inet_pton($value);

        if ($binary === false) {
            throw new InvalidArgumentException("The '{$name}' segment value '{$value}' is not a valid IP address.");
        }

        return $binary;
    }
}
