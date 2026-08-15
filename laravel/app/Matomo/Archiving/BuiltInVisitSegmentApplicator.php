<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Geolocation\CountryMetadataProvider;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\JoinClause;
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

    /** @var array<string, array{literal-string, literal-string, list<int>}> */
    private const array ACTION_LOOKUP_SEGMENTS = [
        'pageUrl' => ['segment_action.idaction_url', 'segment_action_url', [1]],
        'pageTitle' => ['segment_action.idaction_name', 'segment_action_name', [4]],
        'downloadUrl' => ['segment_action.idaction_url', 'segment_action_url', [3]],
        'outlinkUrl' => ['segment_action.idaction_url', 'segment_action_url', [2]],
        'siteSearchKeyword' => ['segment_action.idaction_name', 'segment_action_name', [8]],
        'eventCategory' => ['segment_action.idaction_event_category', 'segment_event_category', [10]],
        'eventAction' => ['segment_action.idaction_event_action', 'segment_event_action', [11]],
        'eventName' => ['segment_action.idaction_name', 'segment_action_name', [12]],
        'eventUrl' => ['segment_action.idaction_url', 'segment_action_url', [10]],
        'contentName' => ['segment_action.idaction_content_name', 'segment_content_name', [13]],
        'contentPiece' => ['segment_action.idaction_content_piece', 'segment_content_piece', [14]],
        'contentTarget' => ['segment_action.idaction_content_target', 'segment_content_target', [15]],
        'contentInteraction' => ['segment_action.idaction_content_interaction', 'segment_content_interaction', [16]],
        'actionUrl' => ['segment_action.idaction_url', 'segment_action_url', [1, 3, 2, 10]],
        'productViewName' => ['segment_action.idaction_product_name', 'segment_product_view_name', [6]],
        'productViewSku' => ['segment_action.idaction_product_sku', 'segment_product_view_sku', [5]],
    ];

    /** @var array<string, array{literal-string, literal-string, list<int>}> */
    private const array VISIT_ACTION_LOOKUP_SEGMENTS = [
        'entryPageUrl' => ['log_visit.visit_entry_idaction_url', 'segment_entry_url', [1]],
        'entryPageTitle' => ['log_visit.visit_entry_idaction_name', 'segment_entry_name', [4]],
        'exitPageUrl' => ['log_visit.visit_exit_idaction_url', 'segment_exit_url', [1]],
        'exitPageTitle' => ['log_visit.visit_exit_idaction_name', 'segment_exit_name', [4]],
    ];

    /** @var array<string, array{literal-string, literal-string}> */
    private const array PRODUCT_VIEW_CATEGORY_LOOKUPS = [
        'productViewCategory1' => ['segment_action.idaction_product_cat', 'segment_product_view_category_1'],
        'productViewCategory2' => ['segment_action.idaction_product_cat2', 'segment_product_view_category_2'],
        'productViewCategory3' => ['segment_action.idaction_product_cat3', 'segment_product_view_category_3'],
        'productViewCategory4' => ['segment_action.idaction_product_cat4', 'segment_product_view_category_4'],
        'productViewCategory5' => ['segment_action.idaction_product_cat5', 'segment_product_view_category_5'],
    ];

    /** @var array<string, array{literal-string, literal-string, int}> */
    private const array PRODUCT_CATEGORY_LOOKUPS = [
        'productCategory1' => ['segment_item.idaction_category', 'segment_product_category_1', 7],
        'productCategory2' => ['segment_item.idaction_category2', 'segment_product_category_2', 7],
        'productCategory3' => ['segment_item.idaction_category3', 'segment_product_category_3', 7],
        'productCategory4' => ['segment_item.idaction_category4', 'segment_product_category_4', 7],
        'productCategory5' => ['segment_item.idaction_category5', 'segment_product_category_5', 7],
    ];

    /** @var array<string, array{literal-string, literal-string}> */
    private const array ACTION_DIRECT_SEGMENTS = [
        'siteSearchCategory' => ['segment_action.search_cat', 'text'],
        'siteSearchCount' => ['segment_action.search_count', 'number'],
        'eventValue' => ['segment_action.custom_float', 'number'],
        'actionServerHour' => ['hour', 'number'],
        'actionServerMinute' => ['minute', 'number'],
        'productViewPrice' => ['segment_action.product_price', 'number'],
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

        /** @var list<list<ResolvedSegmentCondition>> $resolved */
        $resolved = [];
        $hasRelated = false;

        foreach ($groups as $group) {
            $resolvedGroup = [];

            foreach ($group as $condition) {
                $definition = $this->definition($query, $condition->name);

                if ($definition !== null) {
                    [$expression, $type] = $definition;
                    $resolvedGroup[] = new ResolvedSegmentCondition(
                        $condition,
                        $expression,
                        $type,
                    );

                    continue;
                }

                $action = $this->actionDefinition($query, $condition->name);

                if ($action !== null) {
                    $hasRelated = true;
                    $resolvedGroup[] = new ResolvedSegmentCondition(
                        condition: $condition,
                        expression: $action->source === 'type'
                            ? 'segment_action_url.type'
                            : $action->expression,
                        type: $action->type,
                        action: $action,
                    );

                    continue;
                }

                $conversion = $this->conversionDefinition($condition->name);

                if ($conversion === null) {
                    return false;
                }

                if (! $conversion->supports($condition->operator)) {
                    throw new InvalidArgumentException(
                        "The '{$condition->name}' segment does not support the {$condition->operator} operator.",
                    );
                }

                $hasRelated = true;
                $resolvedGroup[] = new ResolvedSegmentCondition(
                    condition: $condition,
                    expression: $conversion->expression,
                    type: $conversion->type,
                    conversion: $conversion,
                );
            }

            $resolved[] = $resolvedGroup;
        }

        if ($hasRelated) {
            $this->applyGroupsWithRelations($query, $resolved);

            return true;
        }

        foreach ($resolved as $group) {
            $query->where(function (Builder $and) use ($group): void {
                foreach ($group as $index => $resolvedCondition) {
                    $callback = function (Builder $operand) use ($resolvedCondition): void {
                        $this->applyCondition(
                            $operand,
                            $resolvedCondition->condition,
                            $resolvedCondition->expression,
                            $resolvedCondition->type,
                        );
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

    /**
     * @param  list<list<ResolvedSegmentCondition>>  $groups
     */
    private function applyGroupsWithRelations(Builder $query, array $groups): void
    {
        /** @var array{action: list<list<ResolvedSegmentCondition>>, conversion: list<list<ResolvedSegmentCondition>>, item: list<list<ResolvedSegmentCondition>>} $combinedGroups */
        $combinedGroups = [
            'action' => [],
            'conversion' => [],
            'item' => [],
        ];

        foreach ($groups as $group) {
            $scope = null;
            $canCombine = true;

            foreach ($group as $resolved) {
                $relatedScope = $resolved->relatedScope();

                if ($relatedScope === null
                    || $resolved->isNegativeRelated()
                    || $resolved->requiresMissingRelationBranch()
                    || ! $resolved->combinesOnSameRow()) {
                    $canCombine = false;

                    break;
                }

                if ($scope === null) {
                    $scope = $relatedScope;
                } elseif ($scope !== $relatedScope) {
                    $canCombine = false;

                    break;
                }
            }

            if ($canCombine && $scope !== null) {
                $combinedGroups[$scope][] = $group;

                continue;
            }

            $this->applyResolvedGroup($query, $group);
        }

        if ($combinedGroups['action'] !== []) {
            $this->applyPositiveActionGroups($query, $combinedGroups['action']);
        }

        if ($combinedGroups['conversion'] !== []) {
            $this->applyPositiveConversionGroups($query, $combinedGroups['conversion']);
        }

        if ($combinedGroups['item'] !== []) {
            $this->applyPositiveConversionGroups($query, $combinedGroups['item']);
        }
    }

    /** @param list<ResolvedSegmentCondition> $group */
    private function applyResolvedGroup(Builder $query, array $group): void
    {
        $query->where(function (Builder $and) use ($group): void {
            foreach ($group as $index => $resolved) {
                $callback = function (Builder $operand) use ($resolved): void {
                    if ($resolved->action !== null) {
                        $this->applyActionExistence($operand, $resolved);

                        return;
                    }

                    if ($resolved->conversion !== null) {
                        $this->applyConversionExistence($operand, $resolved);

                        return;
                    }

                    $this->applyCondition(
                        $operand,
                        $resolved->condition,
                        $resolved->expression,
                        $resolved->type,
                    );
                };

                if ($index === 0) {
                    $and->where($callback);
                } else {
                    $and->orWhere($callback);
                }
            }
        });
    }

    /** @param list<list<ResolvedSegmentCondition>> $groups */
    private function applyPositiveActionGroups(Builder $query, array $groups): void
    {
        $query->whereExists(function (Builder $actions) use ($groups): void {
            $this->startActionSubquery($actions, array_merge(...$groups));

            foreach ($groups as $group) {
                $actions->where(function (Builder $and) use ($group): void {
                    foreach ($group as $index => $resolved) {
                        $callback = function (Builder $operand) use ($resolved): void {
                            $this->applyPositiveActionCondition($operand, $resolved);
                        };

                        if ($index === 0) {
                            $and->where($callback);
                        } else {
                            $and->orWhere($callback);
                        }
                    }
                });
            }
        });
    }

    /** @param list<list<ResolvedSegmentCondition>> $groups */
    private function applyPositiveConversionGroups(Builder $query, array $groups): void
    {
        $query->whereExists(function (Builder $related) use ($groups): void {
            $this->startConversionSubquery($related, array_merge(...$groups));

            foreach ($groups as $group) {
                $related->where(function (Builder $and) use ($group): void {
                    foreach ($group as $index => $resolved) {
                        $callback = function (Builder $operand) use ($resolved): void {
                            $this->applyPositiveConversionCondition($operand, $resolved);
                        };

                        if ($index === 0) {
                            $and->where($callback);
                        } else {
                            $and->orWhere($callback);
                        }
                    }
                });
            }
        });
    }

    private function applyConversionExistence(
        Builder $query,
        ResolvedSegmentCondition $resolved,
    ): void {
        $callback = function (Builder $related) use ($resolved): void {
            $this->startConversionSubquery($related, [$resolved]);

            if ($resolved->isNegativeConversion()) {
                $inverted = new ResolvedSegmentCondition(
                    condition: new SegmentCondition(
                        name: $resolved->condition->name,
                        operator: $resolved->condition->operator === '!=' ? '==' : '=@',
                        value: $resolved->condition->value,
                    ),
                    expression: $resolved->expression,
                    type: $resolved->type,
                    conversion: $resolved->conversion,
                );
                $this->applyPositiveConversionCondition($related, $inverted);

                return;
            }

            $this->applyPositiveConversionCondition($related, $resolved);
        };

        if ($resolved->requiresMissingRelationBranch()) {
            $query->where(function (Builder $empty) use ($callback, $resolved): void {
                $empty->whereNotExists(function (Builder $related) use ($resolved): void {
                    $this->startConversionSubquery($related, [$resolved]);
                })->orWhereExists($callback);
            });

            return;
        }

        if ($resolved->isNegativeConversion()) {
            $query->whereNotExists($callback);
        } else {
            $query->whereExists($callback);
        }
    }

    /** @param list<ResolvedSegmentCondition> $conditions */
    private function startConversionSubquery(Builder $query, array $conditions): void
    {
        $first = $conditions[0]->conversion ?? null;

        if ($first === null) {
            throw new InvalidArgumentException('A conversion segment definition is required.');
        }

        $table = $first->scope === 'conversion'
            ? 'log_conversion as segment_conversion'
            : 'log_conversion_item as segment_item';
        $alias = $first->scope === 'conversion' ? 'segment_conversion' : 'segment_item';
        $query->selectRaw('1')
            ->from($table)
            ->whereColumn("{$alias}.idvisit", 'log_visit.idvisit');
        $joined = [];

        foreach ($conditions as $resolved) {
            $definition = $resolved->conversion;

            if ($definition === null || $definition->scope !== $first->scope) {
                throw new InvalidArgumentException('Related segment scopes cannot be mixed in one subquery.');
            }

            if ($definition->source === 'goal-name' && ! isset($joined['segment_goal'])) {
                $query->leftJoin(
                    'goal as segment_goal',
                    static function (JoinClause $join): void {
                        $join->on('segment_goal.idgoal', '=', 'segment_conversion.idgoal')
                            ->on('segment_goal.idsite', '=', 'segment_conversion.idsite');
                    },
                );
                $joined['segment_goal'] = true;
            }

            foreach ($definition->lookupColumns as [$expression, $lookupAlias]) {
                if (isset($joined[$lookupAlias])) {
                    continue;
                }

                $query->leftJoin(
                    "log_action as {$lookupAlias}",
                    static function (JoinClause $join) use ($expression, $lookupAlias): void {
                        $join->on("{$lookupAlias}.idaction", '=', $expression);
                    },
                );
                $joined[$lookupAlias] = true;
            }
        }
    }

    private function applyPositiveConversionCondition(
        Builder $query,
        ResolvedSegmentCondition $resolved,
    ): void {
        $definition = $resolved->conversion;

        if ($definition === null) {
            throw new InvalidArgumentException('A conversion segment definition is required.');
        }

        if ($definition->discriminatorColumn !== null) {
            $query->where(
                $definition->discriminatorColumn,
                $definition->discriminatorValue,
            );
        }

        if ($definition->source === 'lookup') {
            $this->applyConversionLookupCondition($query, $resolved, $definition);

            return;
        }

        $this->applyCondition(
            $query,
            $resolved->condition,
            $resolved->expression,
            $resolved->type,
        );
    }

    private function applyConversionLookupCondition(
        Builder $query,
        ResolvedSegmentCondition $resolved,
        ConversionSegmentDefinition $definition,
    ): void {
        $query->where(function (Builder $union) use ($resolved, $definition): void {
            foreach ($definition->lookupColumns as $index => [, $lookupAlias, $actionType]) {
                $callback = function (Builder $typed) use (
                    $resolved,
                    $lookupAlias,
                    $actionType,
                ): void {
                    $typed->where("{$lookupAlias}.type", $actionType)
                        ->where(function (Builder $names) use ($resolved, $lookupAlias): void {
                            $variants = array_values(array_unique([
                                $resolved->condition->value,
                                $this->sanitizeActionValue($resolved->condition->value),
                            ]));

                            foreach ($variants as $variantIndex => $variant) {
                                $condition = new SegmentCondition(
                                    $resolved->condition->name,
                                    $resolved->condition->operator,
                                    $variant,
                                );
                                $nameExpression = "{$lookupAlias}.name";
                                $variantCallback = function (Builder $nameQuery) use (
                                    $condition,
                                    $nameExpression,
                                ): void {
                                    $this->applyCondition(
                                        $nameQuery,
                                        $condition,
                                        $nameExpression,
                                        'text',
                                    );
                                };

                                if ($variantIndex === 0) {
                                    $names->where($variantCallback);
                                } else {
                                    $names->orWhere($variantCallback);
                                }
                            }
                        });
                };

                if ($index === 0) {
                    $union->where($callback);
                } else {
                    $union->orWhere($callback);
                }
            }
        });
    }

    private function applyActionExistence(Builder $query, ResolvedSegmentCondition $resolved): void
    {
        if ($resolved->action?->source === 'visit-lookup'
            && $resolved->condition->value === ''
            && in_array($resolved->condition->operator, ['==', '!='], true)) {
            $this->applyEmptyVisitActionLookup($query, $resolved);

            return;
        }

        $callback = function (Builder $actions) use ($resolved): void {
            if ($resolved->action?->source === 'visit-lookup') {
                $this->startVisitActionLookupSubquery($actions, $resolved->action);
            } else {
                $this->startActionSubquery($actions, [$resolved]);
            }

            if ($resolved->isNegativeAction()) {
                $inverted = new ResolvedSegmentCondition(
                    condition: new SegmentCondition(
                        name: $resolved->condition->name,
                        operator: $resolved->condition->operator === '!=' ? '==' : '=@',
                        value: $resolved->condition->value,
                    ),
                    expression: $resolved->expression,
                    type: $resolved->type,
                    action: $resolved->action,
                );
                $this->applyPositiveActionCondition($actions, $inverted);

                return;
            }

            $this->applyPositiveActionCondition($actions, $resolved);
        };

        if ($resolved->requiresMissingRelationBranch()) {
            $query->where(function (Builder $empty) use ($callback): void {
                $empty->whereNotExists(function (Builder $actions): void {
                    $this->startActionSubquery($actions, []);
                })->orWhereExists($callback);
            });

            return;
        }

        if ($resolved->isNegativeAction()) {
            $query->whereNotExists($callback);
        } else {
            $query->whereExists($callback);
        }
    }

    private function applyEmptyVisitActionLookup(
        Builder $query,
        ResolvedSegmentCondition $resolved,
    ): void {
        $definition = $resolved->action;

        if ($definition === null) {
            throw new InvalidArgumentException('A visit action segment definition is required.');
        }

        $emptyLookup = function (Builder $actions) use ($resolved, $definition): void {
            $this->startVisitActionLookupSubquery($actions, $definition);
            $this->applyPositiveActionCondition(
                $actions,
                new ResolvedSegmentCondition(
                    condition: new SegmentCondition(
                        name: $resolved->condition->name,
                        operator: '==',
                        value: '',
                    ),
                    expression: $resolved->expression,
                    type: $resolved->type,
                    action: $definition,
                ),
            );
        };

        if ($resolved->condition->operator === '==') {
            $query->where(function (Builder $empty) use ($definition, $emptyLookup): void {
                $empty->whereNull($definition->expression)->orWhereExists($emptyLookup);
            });

            return;
        }

        $query->whereNotNull($definition->expression)->whereNotExists($emptyLookup);
    }

    private function startVisitActionLookupSubquery(
        Builder $query,
        ActionSegmentDefinition $definition,
    ): void {
        $alias = $definition->lookupAlias;
        $query->selectRaw('1')
            ->from("log_action as {$alias}")
            ->whereColumn("{$alias}.idaction", $definition->expression);
    }

    /** @param list<ResolvedSegmentCondition> $conditions */
    private function startActionSubquery(Builder $query, array $conditions): void
    {
        $query->selectRaw('1')
            ->from('log_link_visit_action as segment_action')
            ->whereColumn('segment_action.idvisit', 'log_visit.idvisit');
        $joined = [];

        foreach ($conditions as $resolved) {
            $definition = $resolved->action;

            if ($definition === null || $definition->source === 'direct') {
                continue;
            }

            foreach ($definition->columns() as [$expression, $alias]) {
                if (isset($joined[$alias])) {
                    continue;
                }

                $query->leftJoin(
                    "log_action as {$alias}",
                    static function (JoinClause $join) use ($alias, $expression): void {
                        $join->on("{$alias}.idaction", '=', $expression);
                    },
                );
                $joined[$alias] = true;
            }
        }
    }

    private function applyPositiveActionCondition(
        Builder $query,
        ResolvedSegmentCondition $resolved,
    ): void {
        $definition = $resolved->action;

        if ($definition === null) {
            throw new InvalidArgumentException('An action segment definition is required.');
        }

        if (in_array($definition->source, ['lookup', 'visit-lookup'], true)) {
            $this->applyActionLookupCondition($query, $resolved, $definition);

            return;
        }

        $this->applyCondition(
            $query,
            $resolved->condition,
            $resolved->expression,
            $resolved->type,
        );
    }

    private function applyActionLookupCondition(
        Builder $query,
        ResolvedSegmentCondition $resolved,
        ActionSegmentDefinition $definition,
    ): void {
        $query->where(function (Builder $union) use ($resolved, $definition): void {
            $branchIndex = 0;

            foreach ($definition->columns() as [, $lookupAlias]) {
                foreach ($definition->actionTypes as $actionType) {
                    $value = $this->normalizeActionValue(
                        $resolved->condition->value,
                        $resolved->condition->name,
                        $actionType,
                    );
                    $callback = function (Builder $typed) use (
                        $resolved,
                        $lookupAlias,
                        $value,
                        $actionType,
                    ): void {
                        $typed->where("{$lookupAlias}.type", $actionType)
                            ->where(function (Builder $names) use ($resolved, $lookupAlias, $value): void {
                                $variants = array_values(array_unique([
                                    $value,
                                    $this->sanitizeActionValue($value),
                                ]));

                                foreach ($variants as $variantIndex => $variant) {
                                    $condition = new SegmentCondition(
                                        $resolved->condition->name,
                                        $resolved->condition->operator,
                                        $variant,
                                    );
                                    $nameExpression = "{$lookupAlias}.name";
                                    $variantCallback = function (Builder $nameQuery) use (
                                        $condition,
                                        $nameExpression,
                                    ): void {
                                        $this->applyCondition(
                                            $nameQuery,
                                            $condition,
                                            $nameExpression,
                                            'text',
                                        );
                                    };

                                    if ($variantIndex === 0) {
                                        $names->where($variantCallback);
                                    } else {
                                        $names->orWhere($variantCallback);
                                    }
                                }
                            });
                    };

                    if ($branchIndex === 0) {
                        $union->where($callback);
                    } else {
                        $union->orWhere($callback);
                    }

                    $branchIndex++;
                }
            }
        });
    }

    private function normalizeActionValue(string $value, string $name, int $actionType): string
    {
        if ($actionType === 1
            || $name === 'eventUrl'
            || ($name === 'actionUrl' && $actionType === 10)) {
            $normalized = preg_replace('@^https?://(www\.)?@i', '', $value);

            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return $value;
    }

    private function sanitizeActionValue(string $value): string
    {
        $value = str_replace(["\n", "\r", "\0"], '', $value);

        return htmlspecialchars(
            html_entity_decode($value, ENT_QUOTES, 'UTF-8'),
            ENT_QUOTES,
            'UTF-8',
        );
    }

    private function actionDefinition(Builder $query, string $name): ?ActionSegmentDefinition
    {
        if ($name === 'productViewCategory') {
            [$expression, $alias] = self::PRODUCT_VIEW_CATEGORY_LOOKUPS['productViewCategory1'];

            return new ActionSegmentDefinition(
                source: 'lookup',
                expression: $expression,
                lookupAlias: $alias,
                actionTypes: [7],
                type: 'text',
                lookupColumns: array_values(self::PRODUCT_VIEW_CATEGORY_LOOKUPS),
            );
        }

        if (isset(self::PRODUCT_VIEW_CATEGORY_LOOKUPS[$name])) {
            [$expression, $alias] = self::PRODUCT_VIEW_CATEGORY_LOOKUPS[$name];

            return new ActionSegmentDefinition(
                source: 'lookup',
                expression: $expression,
                lookupAlias: $alias,
                actionTypes: [7],
                type: 'text',
            );
        }

        if (isset(self::VISIT_ACTION_LOOKUP_SEGMENTS[$name])) {
            [$expression, $alias, $actionTypes] = self::VISIT_ACTION_LOOKUP_SEGMENTS[$name];

            return new ActionSegmentDefinition(
                source: 'visit-lookup',
                expression: $expression,
                lookupAlias: $alias,
                actionTypes: $actionTypes,
                type: 'text',
            );
        }

        if (isset(self::ACTION_LOOKUP_SEGMENTS[$name])) {
            [$expression, $alias, $actionTypes] = self::ACTION_LOOKUP_SEGMENTS[$name];

            return new ActionSegmentDefinition(
                source: 'lookup',
                expression: $expression,
                lookupAlias: $alias,
                actionTypes: $actionTypes,
                type: 'text',
            );
        }

        if ($name === 'actionType') {
            return new ActionSegmentDefinition(
                source: 'type',
                expression: 'segment_action.idaction_url',
                lookupAlias: 'segment_action_url',
                actionTypes: [],
                type: 'action-type',
            );
        }

        if (! isset(self::ACTION_DIRECT_SEGMENTS[$name])) {
            return null;
        }

        [$expression, $type] = self::ACTION_DIRECT_SEGMENTS[$name];

        if (in_array($name, ['actionServerHour', 'actionServerMinute'], true)) {
            $expression = $this->expression($query, $expression, 'server_time');
        }

        return new ActionSegmentDefinition(
            source: 'direct',
            expression: $expression,
            lookupAlias: '',
            actionTypes: [],
            type: $type,
        );
    }

    private function conversionDefinition(string $name): ?ConversionSegmentDefinition
    {
        if ($name === 'productCategory') {
            return new ConversionSegmentDefinition(
                scope: 'item',
                source: 'lookup',
                expression: 'segment_item.idaction_category',
                type: 'text',
                lookupColumns: array_values(self::PRODUCT_CATEGORY_LOOKUPS),
            );
        }

        if (isset(self::PRODUCT_CATEGORY_LOOKUPS[$name])) {
            [$expression, $alias, $actionType] = self::PRODUCT_CATEGORY_LOOKUPS[$name];

            return new ConversionSegmentDefinition(
                scope: 'item',
                source: 'lookup',
                expression: $expression,
                type: 'text',
                lookupColumns: [[$expression, $alias, $actionType]],
            );
        }

        return match ($name) {
            'visitConvertedGoalId' => new ConversionSegmentDefinition(
                scope: 'conversion',
                source: 'direct',
                expression: 'segment_conversion.idgoal',
                type: 'number',
                includeMissingOnEmpty: true,
            ),
            'visitConvertedGoalName' => new ConversionSegmentDefinition(
                scope: 'conversion',
                source: 'goal-name',
                expression: 'segment_goal.name',
                type: 'text',
                includeMissingOnEmpty: true,
            ),
            'orderId' => new ConversionSegmentDefinition(
                scope: 'conversion',
                source: 'direct',
                expression: 'segment_conversion.idorder',
                type: 'text',
                discriminatorColumn: 'segment_conversion.idgoal',
                discriminatorValue: 0,
            ),
            'revenueOrder' => new ConversionSegmentDefinition(
                scope: 'conversion',
                source: 'direct',
                expression: 'segment_conversion.revenue',
                type: 'number',
                discriminatorColumn: 'segment_conversion.idgoal',
                discriminatorValue: 0,
                operators: ['==', '>=', '<=', '>', '<'],
                combineOnSameRow: false,
            ),
            'revenueAbandonedCart' => new ConversionSegmentDefinition(
                scope: 'conversion',
                source: 'direct',
                expression: 'segment_conversion.revenue',
                type: 'number',
                discriminatorColumn: 'segment_conversion.idgoal',
                discriminatorValue: -1,
                operators: ['==', '>=', '<=', '>', '<'],
                combineOnSameRow: false,
            ),
            'productPrice' => new ConversionSegmentDefinition(
                scope: 'item',
                source: 'direct',
                expression: 'segment_item.price',
                type: 'number',
                includeMissingOnEmpty: true,
            ),
            'productName' => new ConversionSegmentDefinition(
                scope: 'item',
                source: 'lookup',
                expression: 'segment_item.idaction_name',
                type: 'text',
                lookupColumns: [[
                    'segment_item.idaction_name',
                    'segment_product_name',
                    6,
                ]],
            ),
            'productSku' => new ConversionSegmentDefinition(
                scope: 'item',
                source: 'lookup',
                expression: 'segment_item.idaction_sku',
                type: 'text',
                lookupColumns: [[
                    'segment_item.idaction_sku',
                    'segment_product_sku',
                    5,
                ]],
            ),
            default => null,
        };
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
        if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/D', $expression) === 1) {
            $this->applyQualifiedCondition($query, $condition, $expression, $type);

            return;
        }

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

    /**
     * @param  literal-string  $expression
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

    /** @param literal-string $expression */
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

    /** @param literal-string $expression */
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
     * @param  literal-string  $expression
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
            'action-type' => $this->enumValue($value, self::ACTION_TYPES, $name),
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
