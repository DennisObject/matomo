<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Dashboard\DashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardRecipientPolicy;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class DashboardApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private DashboardRepository $dashboards,
        private DashboardLayoutProvider $layouts,
        private DashboardRecipientPolicy $recipients,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isDashboardRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Dashboard API handler does not support this method.');
        }

        $parameters = $request->dashboard
            ?? throw new LogicException('The Dashboard API parameters are missing.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        return match ($request->method) {
            'Dashboard.getDashboards' => $this->getDashboards(
                $request,
                $parameters->login,
                $parameters->returnDefaultIfEmpty,
                $language,
            ),
            'Dashboard.createNewDashboardForUser' => $this->create(
                $request,
                $parameters->login,
                $parameters->dashboardName,
                $parameters->addDefaultWidgets,
                $language,
            ),
            'Dashboard.removeDashboard' => $this->remove(
                $request,
                $parameters->dashboardId,
                $parameters->login,
                $language,
            ),
            'Dashboard.copyDashboardToUser' => $this->copy(
                $request,
                $parameters->dashboardId,
                $parameters->copyToUser,
                $parameters->dashboardName,
            ),
            'Dashboard.resetDashboardLayout' => $this->reset(
                $request,
                $parameters->dashboardId,
                $parameters->login,
                $language,
            ),
            default => throw new LogicException('The Dashboard API method is not implemented.'),
        };
    }

    private function getDashboards(
        ApiRequest $request,
        string $login,
        bool $returnDefaultIfEmpty,
        string $language,
    ): Response {
        $currentLogin = $this->authorizer->authenticatedLogin($request->authentication);
        $login = $login !== ''
            ? $login
            : ($this->isAnonymousLogin($currentLogin) ? 'anonymous' : ($currentLogin ?? 'anonymous'));
        $rows = [];

        if (! $this->isAnonymousLogin($currentLogin)) {
            $denied = $this->accessDenied($request, $login, $language);

            if ($denied !== null) {
                return $denied;
            }

            $rows = $this->userDashboards($login, $language);
        }

        if ($rows === [] && $returnDefaultIfEmpty) {
            $layout = $this->layouts->defaultLayout($request->authentication);
            $rows[] = [
                'name' => $this->translator->translate('Dashboard_Dashboard', $language),
                'id' => 1,
                'widgets' => $this->layouts->visibleWidgets($layout),
            ];
        }

        return $this->responses->rows($request, $rows);
    }

    private function create(
        ApiRequest $request,
        string $login,
        string $name,
        bool $addDefaultWidgets,
        string $language,
    ): Response {
        $denied = $this->writeAccessDenied($request, $login, $language);

        if ($denied !== null) {
            return $denied;
        }

        $layout = $addDefaultWidgets ? $this->layouts->defaultLayout($request->authentication) : '{}';

        return $this->responses->scalar(
            $request,
            $this->dashboards->create($login, $name, $layout),
        );
    }

    private function remove(
        ApiRequest $request,
        ?int $dashboardId,
        string $login,
        string $language,
    ): Response {
        $login = $this->targetLogin($request, $login);
        $denied = $this->writeAccessDenied($request, $login, $language);

        if ($denied !== null) {
            return $denied;
        }

        $this->dashboards->delete($login, $this->dashboardId($dashboardId));

        return $this->responses->success($request);
    }

    private function copy(
        ApiRequest $request,
        ?int $dashboardId,
        string $copyToUser,
        string $name,
    ): Response {
        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'admin' access.",
                401,
            );
        }

        if (! $this->recipients->canCopyTo($request->authentication, $copyToUser)) {
            return $this->responses->error(
                $request,
                sprintf('Cannot copy dashboard to user %s, user not found.', $copyToUser),
                400,
            );
        }

        $currentLogin = $this->authorizer->authenticatedLogin($request->authentication);

        if ($currentLogin === null || $this->isAnonymousLogin($currentLogin)) {
            return $this->responses->error($request, 'You must be logged in to access this functionality.', 401);
        }

        $layout = $this->dashboards->layout($currentLogin, $this->dashboardId($dashboardId));

        if ($layout === null) {
            return $this->responses->error($request, 'Dashboard not found', 400);
        }

        return $this->responses->scalar(
            $request,
            $this->dashboards->create($copyToUser, $name, $layout),
        );
    }

    private function reset(
        ApiRequest $request,
        ?int $dashboardId,
        string $login,
        string $language,
    ): Response {
        $login = $this->targetLogin($request, $login);
        $denied = $this->writeAccessDenied($request, $login, $language);

        if ($denied !== null) {
            return $denied;
        }

        $this->dashboards->updateLayout(
            $login,
            $this->dashboardId($dashboardId),
            $this->layouts->defaultLayout($request->authentication),
        );

        return $this->responses->success($request);
    }

    private function writeAccessDenied(ApiRequest $request, string $login, string $language): ?Response
    {
        $currentLogin = $this->authorizer->authenticatedLogin($request->authentication);

        if ($this->isAnonymousLogin($currentLogin)) {
            return $this->responses->error(
                $request,
                $this->translator->translate('General_YouMustBeLoggedIn', $language),
                401,
            );
        }

        if (strtolower($login) === 'anonymous') {
            return $this->responses->error(
                $request,
                "This method can't be performed for anonymous user",
                400,
            );
        }

        return $this->accessDenied($request, $login, $language);
    }

    private function accessDenied(ApiRequest $request, string $login, string $language): ?Response
    {
        if ($this->authorizer->hasSuperUserAccess($request->authentication)
            || $this->authorizer->authenticatedLogin($request->authentication) === $login) {
            return null;
        }

        return $this->responses->error(
            $request,
            $this->translator->translate(
                'General_ExceptionCheckUserHasSuperUserAccessOrIsTheUser',
                $language,
                [$login],
            ),
            401,
        );
    }

    /** @return list<array{name: string, id: int, widgets: list<array{module: string, action: string}>}> */
    private function userDashboards(string $login, string $language): array
    {
        $rows = [];
        $nameless = 1;

        foreach ($this->dashboards->all($login) as $dashboard) {
            $name = $dashboard['name'] ?? '';

            if ($name === '') {
                $name = $this->translator->translate('Dashboard_DashboardOf', $language, [$login]);

                if ($nameless > 1) {
                    $name .= " ({$nameless})";
                }

                $nameless++;
            }

            $rows[] = [
                'name' => htmlspecialchars_decode($name, ENT_QUOTES),
                'id' => $dashboard['iddashboard'],
                'widgets' => $this->layouts->visibleWidgets(
                    $dashboard['layout'] !== '' ? $dashboard['layout'] : '[]',
                ),
            ];
        }

        return $rows;
    }

    private function targetLogin(ApiRequest $request, string $login): string
    {
        if ($login !== '') {
            return $login;
        }

        $currentLogin = $this->authorizer->authenticatedLogin($request->authentication);

        return $this->isAnonymousLogin($currentLogin) ? 'anonymous' : ($currentLogin ?? 'anonymous');
    }

    private function dashboardId(?int $dashboardId): int
    {
        return $dashboardId ?? throw new LogicException('The dashboard ID was not parsed.');
    }

    private function isAnonymousLogin(?string $login): bool
    {
        return $login === null || $login === '' || strtolower($login) === 'anonymous';
    }
}
