<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Dashboard\Events\DashboardDefaultLayoutChanging;
use App\Matomo\Plugins\PluginState;
use Illuminate\Contracts\Events\Dispatcher;
use JsonException;

final readonly class ConfiguredDashboardLayoutProvider implements DashboardLayoutProvider
{
    public function __construct(
        private DashboardRepository $dashboards,
        private PluginState $plugins,
        private ApiAccessAuthorizer $authorizer,
        private Dispatcher $events,
        private bool $professionalServicesAdsEnabled,
    ) {}

    public function defaultLayout(ApiAuthentication $authentication): string
    {
        $layout = $this->dashboards->layout('', 1);

        if (empty($layout)) {
            $layout = $this->builtInLayout();
        }

        if ($this->plugins->isActivated('Tour') && $this->authorizer->hasSuperUserAccess($authentication)) {
            $layout = $this->withTourWidget($layout);
        }

        $event = new DashboardDefaultLayoutChanging($layout);
        $this->events->dispatch($event);

        return $this->normalizedLayout($event->layout);
    }

    public function visibleWidgets(string $layout): array
    {
        $decoded = $this->decode($layout);
        $columns = is_array($decoded)
            ? $decoded
            : (is_object($decoded) && isset($decoded->columns) ? $decoded->columns : []);

        if (! is_array($columns) && ! is_object($columns)) {
            return [];
        }

        $widgets = [];

        $columns = is_object($columns) ? get_object_vars($columns) : $columns;

        foreach ($columns as $column) {
            if (! is_array($column) && ! is_object($column)) {
                continue;
            }

            $column = is_object($column) ? get_object_vars($column) : $column;

            foreach ($column as $widget) {
                if (! is_object($widget) || ! empty($widget->isHidden) || ! isset($widget->parameters)
                    || ! is_object($widget->parameters)) {
                    continue;
                }

                $module = $widget->parameters->module ?? null;
                $action = $widget->parameters->action ?? '';

                if (is_string($module) && $module !== '') {
                    $widgets[] = [
                        'module' => $module,
                        'action' => is_string($action) ? $action : '',
                    ];
                }
            }
        }

        return $widgets;
    }

    private function builtInLayout(): string
    {
        $professionalServices = $this->professionalServicesAdsEnabled
            && $this->plugins->isActivated('ProfessionalServices')
            ? [[
                'uniqueId' => 'widgetProfessionalServicespromoServices',
                'parameters' => ['module' => 'ProfessionalServices', 'action' => 'promoServices'],
            ]]
            : [];
        $insights = $this->plugins->isActivated('Insights')
            ? [[
                'uniqueId' => 'widgetInsightsgetOverallMoversAndShakers',
                'parameters' => ['module' => 'Insights', 'action' => 'getOverallMoversAndShakers'],
            ]]
            : [];

        return json_encode([
            [
                ['uniqueId' => 'widgetLivewidget', 'parameters' => ['module' => 'Live', 'action' => 'widget']],
                [
                    'uniqueId' => 'widgetCoreHomegetPromoVideo',
                    'parameters' => ['module' => 'CoreHome', 'action' => 'getPromoVideo'],
                ],
            ],
            [
                [
                    'uniqueId' => 'widgetVisitsSummarygetEvolutionGraphforceView1viewDataTablegraphEvolution',
                    'parameters' => [
                        'forceView' => '1',
                        'viewDataTable' => 'graphEvolution',
                        'module' => 'VisitsSummary',
                        'action' => 'getEvolutionGraph',
                    ],
                ],
                ...$professionalServices,
                ...$insights,
                [
                    'uniqueId' => 'widgetVisitsSummarygetforceView1viewDataTablesparklines',
                    'parameters' => [
                        'forceView' => '1',
                        'viewDataTable' => 'sparklines',
                        'module' => 'VisitsSummary',
                        'action' => 'get',
                    ],
                ],
            ],
            [
                [
                    'uniqueId' => 'widgetUserCountryMapvisitorMap',
                    'parameters' => ['module' => 'UserCountryMap', 'action' => 'visitorMap'],
                ],
                [
                    'uniqueId' => 'widgetReferrersgetReferrerType',
                    'parameters' => ['module' => 'Referrers', 'action' => 'getReferrerType'],
                ],
                [
                    'uniqueId' => 'widgetRssWidgetrssPiwik',
                    'parameters' => ['module' => 'RssWidget', 'action' => 'rssPiwik'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function withTourWidget(string $layout): string
    {
        $decoded = $this->decode($layout, true);

        if (! is_array($decoded) || ! isset($decoded[2]) || ! is_array($decoded[2])) {
            return $layout;
        }

        array_unshift($decoded[2], [
            'uniqueId' => 'widgetTourgetEngagement',
            'parameters' => ['module' => 'Tour', 'action' => 'getEngagement'],
        ]);

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    private function normalizedLayout(string $layout): string
    {
        $decoded = $this->decode($layout);

        if (is_array($decoded)) {
            $decoded = (object) ['config' => ['layout' => '33-33-33'], 'columns' => $decoded];
        }

        if (! is_object($decoded) || ! isset($decoded->columns)) {
            $decoded = (object) ['config' => ['layout' => '33-33-33'], 'columns' => []];
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    private function decode(string $layout, bool $associative = false): mixed
    {
        $layout = html_entity_decode($layout, ENT_COMPAT | ENT_HTML401, 'UTF-8');
        $layout = str_replace(['\\"', "\n"], ['"', ''], $layout);

        try {
            return json_decode($layout, $associative, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
