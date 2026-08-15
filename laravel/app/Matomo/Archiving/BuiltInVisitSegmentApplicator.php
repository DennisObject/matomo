<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Geolocation\CountryMetadataProvider;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use InvalidArgumentException;

final readonly class BuiltInVisitSegmentApplicator implements VisitSegmentApplicator
{
    /** @var array<string, array{literal-string, literal-string}> */
    private const array DIRECT_SEGMENTS = [
        'visitConverted' => ['visit_goal_converted', 'boolean'],
        'visitorId' => ['idvisitor', 'visitor-id'],
        'visitDuration' => ['visit_total_time', 'number'],
        'profilable' => ['profilable', 'boolean'],
        'visitIp' => ['location_ip', 'ip'],
        'userId' => ['user_id', 'text'],
        'secondsSinceLastEcommerceOrder' => ['visitor_seconds_since_order', 'number'],
        'visitCount' => ['visitor_count_visits', 'number'],
        'fingerprint' => ['config_id', 'visitor-id'],
        'visitId' => ['idvisit', 'number'],
        'visitorType' => ['visitor_returning', 'visitor-type'],
        'visitEcommerceStatus' => ['visit_goal_buyer', 'ecommerce-status'],
        'secondsSinceFirstVisit' => ['visitor_seconds_since_first', 'number'],
        'interactions' => ['visit_total_interactions', 'number'],
        'searches' => ['visit_total_searches', 'number'],
        'actions' => ['visit_total_actions', 'number'],
        'events' => ['visit_total_events', 'number'],
        'deviceModel' => ['config_device_model', 'text'],
        'browserVersion' => ['config_browser_version', 'text'],
        'operatingSystemVersion' => ['config_os_version', 'text'],
        'deviceType' => ['config_device_type', 'device-type'],
        'deviceBrand' => ['config_device_brand', 'text'],
        'browserCode' => ['config_browser_name', 'text'],
        'browserEngine' => ['config_browser_engine', 'text'],
        'operatingSystemCode' => ['config_os', 'text'],
        'referrerType' => ['referer_type', 'referrer-type'],
        'referrerName' => ['referer_name', 'text'],
        'referrerKeyword' => ['referer_keyword', 'text'],
        'referrerUrl' => ['referer_url', 'text'],
        'latitude' => ['location_latitude', 'number'],
        'city' => ['location_city', 'text'],
        'longitude' => ['location_longitude', 'number'],
        'regionCode' => ['location_region', 'text'],
        'countryCode' => ['location_country', 'text'],
        'continentCode' => ['location_country', 'continent'],
        'provider' => ['location_provider', 'text'],
        'languageCode' => ['location_browser_lang', 'text'],
        'resolution' => ['config_resolution', 'text'],
        'aiAgentName' => ['ai_agent_name', 'text'],
    ];

    /** @var array<string, array{literal-string, literal-string}> */
    private const array EXPRESSION_SEGMENTS = [
        'visitEndServerYear' => ['year', 'visit_last_action_time'],
        'visitStartServerHour' => ['hour', 'visit_first_action_time'],
        'visitEndServerMinute' => ['minute', 'visit_last_action_time'],
        'visitEndServerDayOfWeek' => ['day-of-week', 'visit_last_action_time'],
        'visitEndServerSecond' => ['second', 'visit_last_action_time'],
        'visitEndServerWeekOfYear' => ['week-of-year', 'visit_last_action_time'],
        'visitEndServerDayOfYear' => ['day-of-year', 'visit_last_action_time'],
        'visitStartServerMinute' => ['minute', 'visit_first_action_time'],
        'visitEndServerQuarter' => ['quarter', 'visit_last_action_time'],
        'daysSinceFirstVisit' => ['rounded-days', 'visitor_seconds_since_first'],
        'visitEndServerDate' => ['date', 'visit_last_action_time'],
        'visitEndServerDayOfMonth' => ['day-of-month', 'visit_last_action_time'],
        'visitServerHour' => ['hour', 'visit_last_action_time'],
        'visitEndServerMonth' => ['month', 'visit_last_action_time'],
        'daysSinceLastEcommerceOrder' => ['days', 'visitor_seconds_since_order'],
        'daysSinceLastVisit' => ['days', 'visitor_seconds_since_last'],
        'secondsSinceLastVisit' => ['direct', 'visitor_seconds_since_last'],
        'visitLocalMinute' => ['minute', 'visitor_localtime'],
        'visitLocalHour' => ['hour', 'visitor_localtime'],
    ];

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

    /** @var array<string, list<string>> */
    private array $countriesByContinent;

    public function __construct(
        private SegmentExpressionParser $parser,
        CountryMetadataProvider $countries,
    ) {
        $countriesByContinent = [];

        foreach ($countries->codes() as $country) {
            $continent = $countries->continentCode($country);
            $countriesByContinent[$continent][] = $country;
        }

        $this->countriesByContinent = $countriesByContinent;
    }

    public function apply(Builder $query, ?string $segment): bool
    {
        $groups = $this->parser->parse($segment);

        if ($groups === []) {
            return true;
        }

        $resolved = [];

        foreach ($groups as $group) {
            $resolvedGroup = [];

            foreach ($group as $condition) {
                $definition = $this->definition($query, $condition->name);

                if ($definition === null) {
                    return false;
                }

                [$expression, $type] = $definition;
                $resolvedGroup[] = [$condition, $expression, $type];
            }

            $resolved[] = $resolvedGroup;
        }

        foreach ($resolved as $group) {
            $query->where(function (Builder $and) use ($group): void {
                foreach ($group as $index => [$condition, $expression, $type]) {
                    $callback = function (Builder $operand) use ($condition, $expression, $type): void {
                        $this->applyCondition($operand, $condition, $expression, $type);
                    };

                    if ($index === 0) {
                        $and->where($callback);
                    } else {
                        $and->orWhere($callback);
                    }
                }
            });
        }

        return true;
    }

    /** @return array{literal-string, literal-string}|null */
    private function definition(Builder $query, string $name): ?array
    {
        if (isset(self::DIRECT_SEGMENTS[$name])) {
            return self::DIRECT_SEGMENTS[$name];
        }

        if (! isset(self::EXPRESSION_SEGMENTS[$name])) {
            return null;
        }

        [$function, $column] = self::EXPRESSION_SEGMENTS[$name];

        return [
            $this->expression($query, $function, $column),
            $function === 'date' ? 'text' : 'number',
        ];
    }

    /**
     * @param  literal-string  $function
     * @param  literal-string  $column
     * @return literal-string
     */
    private function expression(Builder $query, string $function, string $column): string
    {
        if ($function === 'direct') {
            return $column;
        }

        if (! $query->getGrammar() instanceof SQLiteGrammar) {
            return match ($function) {
                'year' => "YEAR({$column})",
                'hour' => "HOUR({$column})",
                'minute' => "MINUTE({$column})",
                'day-of-week' => "DAYOFWEEK({$column})",
                'second' => "SECOND({$column})",
                'week-of-year' => "WEEKOFYEAR({$column})",
                'day-of-year' => "DAYOFYEAR({$column})",
                'quarter' => "QUARTER({$column})",
                'rounded-days' => "ROUND({$column} / 86400)",
                'date' => "DATE({$column})",
                'day-of-month' => "DAYOFMONTH({$column})",
                'month' => "MONTH({$column})",
                'days' => "FLOOR({$column} / 86400)",
                default => throw new InvalidArgumentException("The segment date function '{$function}' is not valid."),
            };
        }

        return match ($function) {
            'year' => "CAST(strftime('%Y', {$column}) AS INTEGER)",
            'hour' => "CAST(strftime('%H', {$column}) AS INTEGER)",
            'minute' => "CAST(strftime('%M', {$column}) AS INTEGER)",
            'day-of-week' => "CAST(strftime('%w', {$column}) AS INTEGER) + 1",
            'second' => "CAST(strftime('%S', {$column}) AS INTEGER)",
            'week-of-year' => "CAST(strftime('%W', date({$column}, '-3 days', 'weekday 4')) AS INTEGER) + 1",
            'day-of-year' => "CAST(strftime('%j', {$column}) AS INTEGER)",
            'quarter' => "(CAST(strftime('%m', {$column}) AS INTEGER) + 2) / 3",
            'rounded-days' => "ROUND({$column} / 86400.0)",
            'date' => "date({$column})",
            'day-of-month' => "CAST(strftime('%d', {$column}) AS INTEGER)",
            'month' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            'days' => "CAST({$column} / 86400 AS INTEGER)",
            default => throw new InvalidArgumentException("The segment date function '{$function}' is not valid."),
        };
    }

    /**
     * @param  literal-string  $expression
     * @param  literal-string  $type
     */
    private function applyCondition(
        Builder $query,
        SegmentCondition $condition,
        string $expression,
        string $type,
    ): void {
        if ($condition->value === '') {
            $this->applyEmptyCondition($query, $condition->operator, $expression);

            return;
        }

        if ($type === 'continent') {
            $this->applyContinentCondition($query, $condition, $expression);

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

    /** @param literal-string $expression */
    private function applyEmptyCondition(Builder $query, string $operator, string $expression): void
    {
        if ($operator === '==') {
            $query->where(function (Builder $empty) use ($expression): void {
                $empty->whereRaw("{$expression} IS NULL")
                    ->orWhereRaw("{$expression} = ''")
                    ->orWhereRaw("{$expression} = '0'");
            });

            return;
        }

        if ($operator === '!=') {
            $query->whereRaw("{$expression} IS NOT NULL")
                ->whereRaw("{$expression} <> ''")
                ->whereRaw("{$expression} <> '0'");

            return;
        }

        throw new InvalidArgumentException("The segment operator '{$operator}' requires a value.");
    }

    /** @param literal-string $expression */
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
     * @param  literal-string  $expression
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
        $query->whereRaw("{$expression} {$operator} {$placeholder}", [$value]);
    }

    /** @param literal-string $expression */
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
            $notEqual->whereRaw("{$expression} IS NULL")
                ->orWhere(function (Builder $present) use ($expression, $value, $numeric): void {
                    $this->whereComparison($present, $expression, '<>', $value, $numeric);
                });
        });
    }

    /** @param literal-string $expression */
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
            $notContains->whereRaw("{$expression} IS NULL")
                ->orWhere(function (Builder $present) use ($expression, $value): void {
                    $this->whereLike($present, $expression, $value, 'contains', true);
                });
        });
    }

    /**
     * @param  literal-string  $expression
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
        $query->whereRaw("{$expression} {$operator} ?{$escape}", [$pattern]);
    }

    private function filterValue(string $value, string $type, string $name): mixed
    {
        return match ($type) {
            'text' => $value,
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
            default => throw new InvalidArgumentException("The '{$name}' segment type '{$type}' is not valid."),
        };
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
