<?php

declare(strict_types=1);

namespace App\Matomo\Tour;

use App\Matomo\Api\Events\TourChallengesCollecting;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Sites\ConsentManagerDetector;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class TourChallengeCatalog
{
    public function __construct(
        private TourDataRepository $data,
        private PluginState $plugins,
        private OptionRepository $options,
        private TourSettings $settings,
        private MatomoTranslator $translator,
        private SiteRepository $sites,
        private ConsentManagerDetector $consentManagers,
        private Dispatcher $events,
    ) {}

    /**
     * @return list<array{id: string, name: string, description: string, isCompleted: bool, isSkipped: bool, url: string}>
     */
    public function challenges(string $login, string $language, ?int $idSite): array
    {
        $progress = $this->data->progress($login);
        $definitions = $this->definitions($login, $language, $idSite);
        $challenges = [];

        foreach ($definitions as $definition) {
            $id = $definition['id'];
            $completed = $definition['completed']
                ?? ($progress[$id.'_completed'] ?? false);
            $challenges[] = [
                'id' => $id,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'isCompleted' => $completed,
                'isSkipped' => $progress[$id.'_skipped'] ?? false,
                'url' => $definition['url'],
            ];
        }

        $event = new TourChallengesCollecting($challenges);
        $this->events->dispatch($event);

        return $event->challenges;
    }

    public function skip(string $login, string $challengeId, string $language, ?int $idSite): string
    {
        foreach ($this->challenges($login, $language, $idSite) as $challenge) {
            if ($challenge['id'] !== $challengeId) {
                continue;
            }

            if ($challenge['isCompleted']) {
                return 'completed';
            }

            if (! $challenge['isSkipped']) {
                $this->data->skip($login, $challengeId);
            }

            return 'skipped';
        }

        return 'missing';
    }

    /**
     * @param  list<array{id: string, name: string, description: string, isCompleted: bool, isSkipped: bool, url: string}>  $challenges
     * @return array{description: string, currentLevel: int, currentLevelName: string, nextLevelName: string|null, numLevelsTotal: int, challengesNeededForNextLevel: int|null}
     */
    public function level(array $challenges, string $login, string $language): array
    {
        $completed = count(array_filter(
            $challenges,
            static fn (array $challenge): bool => $challenge['isCompleted'] || $challenge['isSkipped'],
        ));
        $total = count($challenges);
        $levels = [
            0 => $this->translate('Tour_MatomoBeginner', $language),
            5 => $this->translate('Tour_MatomoIntermediate', $language),
        ];

        if ($total > 10) {
            $levels[10] = $this->translate('Tour_MatomoTalent', $language);
        }

        if ($total > 15) {
            $levels[15] = $this->translate('Tour_MatomoProfessional', $language);
        }

        $levels[$total] = $this->translate('Tour_MatomoExpert', $language);
        ksort($levels);
        $currentLevel = 0;
        $currentLevelName = '';
        $nextLevelName = null;
        $needed = null;

        foreach ($levels as $threshold => $name) {
            if ($completed >= $threshold) {
                $currentLevel++;
                $currentLevelName = $name;

                continue;
            }

            $nextLevelName = $name;
            $needed = $threshold - $completed;
            break;
        }

        $part = match (true) {
            $completed <= $total / 4 => 'Tour_Part1Title',
            $completed <= $total / 2 => 'Tour_Part2Title',
            $completed <= $total / 1.333 => 'Tour_Part3Title',
            default => 'Tour_Part4Title',
        };

        return [
            'description' => $this->translate($part, $language, [$login]),
            'currentLevel' => $currentLevel,
            'currentLevelName' => $currentLevelName,
            'nextLevelName' => $nextLevelName,
            'numLevelsTotal' => count($levels),
            'challengesNeededForNextLevel' => $needed,
        ];
    }

    /**
     * @return list<array{id: string, name: string, description: string, url: string, completed?: bool}>
     */
    private function definitions(string $login, string $language, ?int $idSite): array
    {
        $definitions = [
            $this->definition(
                'track_data',
                'Tour_EmbedTrackingCode',
                'CoreAdminHome_TrackingCodeIntro',
                $language,
                $this->internalUrl('CoreAdminHome', 'trackingCodeGenerator', $idSite),
                $this->data->hasTrackedData(),
            ),
        ];
        $consentManager = $this->consentManager($idSite);

        if ($consentManager !== null) {
            $definitions[] = [
                'id' => 'setup_consent_manager',
                'name' => $this->translate('Tour_ConnectConsentManager', $language, [$consentManager['name']]),
                'description' => $this->translate(
                    'Tour_ConnectConsentManagerIntro',
                    $language,
                    [$consentManager['name']],
                ),
                'url' => '',
                'completed' => $consentManager['isConnected'],
            ];
        }

        if ($this->plugins->isActivated('Goals')) {
            $definitions[] = $this->definition(
                'define_goal',
                'Tour_DefineGoal',
                'Tour_DefineGoalDescription',
                $language,
                $this->internalUrl('Goals', 'manage', $idSite),
            );
        }

        $definitions[] = $this->definition(
            'custom_logo',
            'Tour_UploadLogo',
            'CoreAdminHome_CustomLogoHelpText',
            $language,
            $this->internalUrl('CoreAdminHome', 'generalSettings', $idSite).'#/#useCustomLogo',
            $this->settings->customLogoEnabled()
                && $this->options->value('branding_use_custom_logo') === '1',
        );

        if ($this->plugins->isActivated('UsersManager') && $this->settings->usersAdminEnabled()) {
            $definitions[] = $this->definition(
                'add_user',
                'Tour_InviteUser',
                'UsersManager_PluginDescription',
                $language,
                $this->internalUrl('UsersManager', 'index', $idSite),
            );
        }

        if ($this->plugins->isActivated('SitesManager') && $this->settings->sitesAdminEnabled()) {
            $definitions[] = $this->definition(
                'add_website',
                'Tour_AddAnotherWebsite',
                'SitesManager_PluginDescription',
                $language,
                $this->internalUrl('SitesManager', 'index', $idSite),
                $this->data->hasAddedWebsite($login),
            );
        }

        $definitions[] = $this->definition(
            'flatten_actions',
            'Tour_FlattenActions',
            'Tour_FlattenActionsDescription',
            $language,
        );
        $definitions[] = $this->definition(
            'change_visualisations',
            'Tour_ChangeVisualisation',
            'Tour_ChangeVisualisationDescription',
            $language,
        );

        if ($this->plugins->isActivated('ScheduledReports')) {
            $definitions[] = $this->definition(
                'add_scheduled_report',
                'Tour_AddReport',
                'ScheduledReports_PluginDescription',
                $language,
                $this->internalUrl('ScheduledReports', 'index', $idSite),
                $this->data->hasAddedScheduledReport($login),
            );
        }

        if ($this->plugins->isActivated('Dashboard')) {
            $definitions[] = $this->definition(
                'customise_dashboard',
                'Tour_CustomiseDashboard',
                'Tour_CustomiseDashboardDescription',
                $language,
                completed: $this->data->hasCustomizedDashboard($login),
            );
        }

        if ($this->plugins->isActivated('SegmentEditor')) {
            $definitions[] = $this->definition(
                'add_segment',
                'Tour_AddSegment',
                'SegmentEditor_PluginDescription',
                $language,
                completed: $this->data->hasAddedSegment($login),
            );
        }

        if ($this->plugins->isActivated('Annotations')) {
            $definitions[] = $this->definition(
                'add_annotation',
                'Tour_AddAnnotation',
                'Annotations_PluginDescription',
                $language,
            );
        }

        if ($this->plugins->isActivated('TwoFactorAuth')) {
            $definitions[] = $this->definition(
                'setup_twofa',
                'Tour_SetupX',
                'TwoFactorAuth_TwoFactorAuthenticationIntro',
                $language,
                nameArguments: [$this->translate('TwoFactorAuth_TwoFactorAuthentication', $language)],
                descriptionArguments: ['', ''],
                completed: $this->data->usesTwoFactorAuthentication($login),
            );
        }

        if ($this->settings->generalSettingsAdminEnabled()) {
            $definitions[] = $this->definition(
                'disable_browser_archiving',
                'Tour_DisableBrowserArchiving',
                '',
                $language,
                completed: ! $this->settings->browserArchivingTriggerEnabled(),
            );
        }

        if ($this->settings->geolocationAdminEnabled()) {
            $provider = $this->options->value('usercountry.location_provider');
            $definitions[] = $this->definition(
                'configure_geolocation',
                'Tour_ConfigureGeolocation',
                'Tour_ConfigureGeolocationDescription',
                $language,
                $this->internalUrl('UserCountry', 'adminIndex', $idSite),
                is_string($provider) && $provider !== '' && $provider !== 'default',
            );
        }

        $definitions[] = $this->definition(
            'select_date_range',
            'Tour_SelectDateRange',
            'Tour_SelectDateRangeDescription',
            $language,
        );

        if ($this->plugins->isActivated('Live')) {
            $definitions[] = $this->definition(
                'view_visits_log',
                'Tour_ViewX',
                'Tour_ViewVisitsLogDescription',
                $language,
                nameArguments: [$this->translate('Live_VisitsLog', $language)],
            );
            $definitions[] = $this->definition(
                'view_visitor_profile',
                'Tour_ViewX',
                'Tour_ViewVisitorProfileDescription',
                $language,
                nameArguments: [$this->translate('Live_VisitorProfile', $language)],
            );
        }

        $definitions[] = $this->definition(
            'view_row_evolution',
            'Tour_ViewX',
            'Tour_ViewRowEvolutionDescription',
            $language,
            nameArguments: [$this->translate('Tour_RowEvolution', $language)],
        );

        if ($this->plugins->isActivated('Marketplace')) {
            $definitions[] = $this->definition(
                'browse_marketplace',
                'Tour_BrowseMarketplace',
                'Marketplace_PluginDescription',
                $language,
                $this->internalUrl('Marketplace', 'overview', $idSite),
            );
        }

        return $definitions;
    }

    /**
     * @param  list<string>  $nameArguments
     * @param  list<string>  $descriptionArguments
     * @return array{id: string, name: string, description: string, url: string, completed?: bool}
     */
    private function definition(
        string $id,
        string $name,
        string $description,
        string $language,
        string $url = '',
        ?bool $completed = null,
        array $nameArguments = [],
        array $descriptionArguments = [],
    ): array {
        $definition = [
            'id' => $id,
            'name' => $this->translate($name, $language, $nameArguments),
            'description' => $description === ''
                ? ''
                : $this->translate($description, $language, $descriptionArguments),
            'url' => $url,
        ];

        if ($completed !== null) {
            $definition['completed'] = $completed;
        }

        return $definition;
    }

    /** @return array{name: string, url: string|null, isConnected: bool}|null */
    private function consentManager(?int $idSite): ?array
    {
        if ($idSite === null) {
            return null;
        }

        $url = $this->sites->mainUrl($idSite);

        return $url === null ? null : $this->consentManagers->detect($url, 60);
    }

    /** @param list<string> $arguments */
    private function translate(string $key, string $language, array $arguments = []): string
    {
        return $this->translator->translate($key, $language, $arguments);
    }

    private function internalUrl(string $module, string $action, ?int $idSite): string
    {
        $parameters = ['module' => $module, 'action' => $action];

        if ($idSite !== null) {
            $parameters['idSite'] = $idSite;
        }

        return 'index.php?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
