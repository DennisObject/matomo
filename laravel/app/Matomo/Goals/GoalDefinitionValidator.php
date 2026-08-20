<?php

declare(strict_types=1);

namespace App\Matomo\Goals;

use App\Matomo\Api\GoalDefinition;
use App\Matomo\Localization\MatomoTranslator;
use InvalidArgumentException;

final readonly class GoalDefinitionValidator
{
    /** @var list<string> */
    private const array NUMERIC_MATCH_ATTRIBUTES = ['visit_duration'];

    /** @var list<string> */
    private const array EVENT_MATCH_ATTRIBUTES = ['event_action', 'event_name', 'event_category'];

    public function __construct(private MatomoTranslator $translator) {}

    public function validate(GoalDefinition $goal, string $language, bool $updating): GoalDefinition
    {
        $patternType = empty($goal->patternType) ? '' : strtolower($goal->patternType);
        $allowedPatternTypes = in_array($goal->matchAttribute, self::NUMERIC_MATCH_ATTRIBUTES, true)
            ? ['greater_than']
            : ['exact', 'contains', 'regex'];

        if ($patternType !== '' && ! in_array($patternType, $allowedPatternTypes, true)) {
            throw new InvalidArgumentException($this->translator->translate(
                'General_ValidatorErrorXNotWhitelisted',
                $language,
                [$patternType, implode(', ', $allowedPatternTypes)],
            ));
        }

        if ($goal->matchAttribute !== 'manually' && $goal->pattern === '') {
            throw new InvalidArgumentException($this->translator->translate(
                'General_PleaseSpecifyValue',
                $language,
                ['pattern'],
            ));
        }

        if (in_array($goal->matchAttribute, self::NUMERIC_MATCH_ATTRIBUTES, true)
            && ! is_numeric($goal->pattern)) {
            throw new InvalidArgumentException(
                "Invalid pattern for match attribute '{$goal->matchAttribute}'. ".
                "(got '{$goal->pattern}', expected numeric value).",
            );
        }

        if ($patternType === 'exact'
            && ! str_starts_with($goal->pattern, 'http')
            && ! str_starts_with($goal->matchAttribute, 'event_')
            && $goal->matchAttribute !== 'title') {
            throw new InvalidArgumentException($this->translator->translate(
                'Goals_ExceptionInvalidMatchingString',
                $language,
                ['http:// or https://', 'http://www.yourwebsite.com/newsletter/subscribed.html'],
            ));
        }

        if ($patternType === 'regex') {
            $pattern = str_contains($goal->pattern, '/') && ! str_contains($goal->pattern, '\\/')
                ? str_replace('/', '\\/', $goal->pattern)
                : $goal->pattern;
            $regularExpression = '/'.$pattern.'/';

            if (@preg_match($regularExpression, '') === false) {
                throw new InvalidArgumentException($this->translator->translate(
                    'General_ValidatorErrorNoValidRegex',
                    $language,
                    [$regularExpression],
                ));
            }
        }

        if ($updating
            && $goal->useEventValueAsRevenue
            && ! in_array($goal->matchAttribute, self::EVENT_MATCH_ATTRIBUTES, true)) {
            throw new InvalidArgumentException(
                "'useEventValueAsRevenue' can only be 1 if the goal matches an event attribute.",
            );
        }

        return new GoalDefinition(
            name: $goal->name,
            matchAttribute: $goal->matchAttribute,
            pattern: $goal->pattern,
            patternType: $patternType,
            caseSensitive: $goal->caseSensitive,
            revenue: $goal->revenue,
            allowMultipleConversionsPerVisit: $goal->allowMultipleConversionsPerVisit,
            description: $goal->description,
            useEventValueAsRevenue: $goal->useEventValueAsRevenue,
        );
    }
}
