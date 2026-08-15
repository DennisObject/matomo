<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\GoalDefinition;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Goals\GoalDefinitionValidator;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Localization\LanguageResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class GoalsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private GoalRepository $goals,
        private GoalDefinitionValidator $validator,
        private SiteTrackerCacheInvalidator $trackerCache,
        private LanguageResolver $languages,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isGoalsManagementRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Goals API handler does not support this method.');
        }

        $parameters = $request->goals
            ?? throw new LogicException('The Goals API parameters are missing.');
        $siteIds = $parameters->allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            )
            : $parameters->siteIds;

        if ($request->method === 'Goals.getGoals') {
            return $this->getGoals($request, $siteIds, $parameters->allSites, $parameters->orderByName);
        }

        $siteId = $siteIds[0];

        if ($request->method === 'Goals.getGoal') {
            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
                return $this->viewAccessError($request, $siteId);
            }

            $goal = $this->goals->findActive($siteId, $parameters->idGoal ?? 0);

            return $goal === null
                ? $this->responses->success($request)
                : $this->responses->row($request, $goal);
        }

        if (! $this->hasWriteAccess($request, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'write' access for the website id = {$siteId}.",
                401,
            );
        }

        return match ($request->method) {
            'Goals.addGoal' => $this->add($request, $httpRequest, $siteId),
            'Goals.updateGoal' => $this->update(
                $request,
                $httpRequest,
                $siteId,
                $parameters->idGoal ?? 0,
            ),
            'Goals.deleteGoal' => $this->delete($request, $siteId, $parameters->idGoal ?? 0),
            default => throw new LogicException('The Goals management API method is not implemented.'),
        };
    }

    /** @param list<int> $siteIds */
    private function getGoals(
        ApiRequest $request,
        array $siteIds,
        bool $allSites,
        bool $orderByName,
    ): Response {
        if (! $allSites) {
            foreach ($siteIds as $siteId) {
                if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
                    return $this->viewAccessError($request, $siteId);
                }
            }
        }

        $goals = $this->goals->activeForSites($siteIds);
        $indexByGoalId = count($siteIds) === 1;
        $result = [];

        foreach ($goals as $goal) {
            if ($indexByGoalId) {
                $result[(int) ($goal['idgoal'] ?? 0)] = $goal;
            } else {
                $result[] = $goal;
            }
        }

        if ($orderByName) {
            uasort($result, static function (array $left, array $right): int {
                $leftName = (string) ($left['name'] ?? '');
                $rightName = (string) ($right['name'] ?? '');

                if ($leftName === $rightName) {
                    return (int) ($left['idgoal'] ?? 0) > (int) ($right['idgoal'] ?? 0) ? -1 : 1;
                }

                return strcasecmp($leftName, $rightName);
            });
        }

        return $indexByGoalId
            ? $this->responses->keyedRows($request, $result)
            : $this->responses->rows($request, array_values($result));
    }

    private function add(ApiRequest $request, Request $httpRequest, int $siteId): Response
    {
        $goal = $this->validGoal($request, $httpRequest, false);

        if ($goal instanceof Response) {
            return $goal;
        }

        $goalId = $this->goals->create($siteId, $goal);
        $this->trackerCache->clear($siteId);

        return $this->responses->scalar($request, $goalId);
    }

    private function update(
        ApiRequest $request,
        Request $httpRequest,
        int $siteId,
        int $goalId,
    ): Response {
        $goal = $this->validGoal($request, $httpRequest, true);

        if ($goal instanceof Response) {
            return $goal;
        }

        $this->goals->update($siteId, $goalId, $goal);
        $this->trackerCache->clear($siteId);

        return $this->responses->success($request);
    }

    private function delete(ApiRequest $request, int $siteId, int $goalId): Response
    {
        $this->goals->delete($siteId, $goalId);
        $this->trackerCache->clear($siteId);

        return $this->responses->success($request);
    }

    private function validGoal(
        ApiRequest $request,
        Request $httpRequest,
        bool $updating,
    ): GoalDefinition|Response {
        $goal = $request->goals->definition
            ?? throw new LogicException('The goal definition is missing.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        try {
            return $this->validator->validate($goal, $language, $updating);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }
    }

    private function hasWriteAccess(ApiRequest $request, int $siteId): bool
    {
        return $this->authorizer->hasSuperUserAccess($request->authentication)
            || in_array(
                $siteId,
                $this->authorizer->siteIdsWithMinimumRole(
                    $request->authentication,
                    SiteAccessRole::Write,
                ),
                true,
            );
    }

    private function viewAccessError(ApiRequest $request, int $siteId): Response
    {
        return $this->responses->error(
            $request,
            "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
            401,
        );
    }
}
