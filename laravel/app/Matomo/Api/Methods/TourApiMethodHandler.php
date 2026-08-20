<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Tour\TourChallengeCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class TourApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private TourChallengeCatalog $catalog,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isTourRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Tour API handler does not support this method.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if ($login === null || $login === '') {
            return $this->responses->error($request, 'You must be logged in.', 401);
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);

        if ($request->method === 'Tour.skipChallenge') {
            return $this->skip($request, $login, $language);
        }

        $challenges = $this->catalog->challenges($login, $language, $request->idSite);

        return $request->method === 'Tour.getLevel'
            ? $this->responses->row($request, $this->catalog->level($challenges, $login, $language))
            : $this->responses->rows($request, $challenges);
    }

    private function skip(ApiRequest $request, string $login, string $language): Response
    {
        $result = $this->catalog->skip(
            $login,
            $request->tourChallengeId ?? '',
            $language,
            $request->idSite,
        );

        return match ($result) {
            'skipped' => $this->responses->scalar($request, true),
            'completed' => $this->responses->error($request, 'Challenge already completed', 400),
            default => $this->responses->error($request, 'Challenge not found', 400),
        };
    }
}
