<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\CustomDimensions\CustomDimensionManager;
use App\Matomo\Localization\LanguageResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final readonly class CustomDimensionsMutationApiMethodHandler implements ApiMethodHandler
{
    /** @var list<string> */
    private const array METHODS = [
        'CustomDimensions.configureNewCustomDimension',
        'CustomDimensions.configureExistingCustomDimension',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private CustomDimensionManager $dimensions,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->module === 'API' && in_array($request->method, self::METHODS, true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The CustomDimensions mutation handler does not support this request.');
        }

        $query = $request->customDimensions
            ?? throw new LogicException('The CustomDimensions request was not parsed.');
        $siteId = $query->siteId ?? throw new LogicException('The site ID was not parsed.');

        if (! in_array(
            $siteId,
            $this->authorizer->siteIdsWithMinimumRole($request->authentication, SiteAccessRole::Write),
            true,
        )) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'write' access for the website id = {$siteId}.",
                401,
            );
        }

        $name = $query->name ?? throw new LogicException('The dimension name was not parsed.');
        $active = $query->active ?? throw new LogicException('The active state was not parsed.');
        $extractions = $query->extractions ?? throw new LogicException('The extractions were not parsed.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        try {
            if ($request->method === 'CustomDimensions.configureNewCustomDimension') {
                $dimensionId = $this->dimensions->create(
                    siteId: $siteId,
                    name: $name,
                    scope: $query->scope ?? throw new LogicException('The scope was not parsed.'),
                    active: $active,
                    extractions: $extractions,
                    caseSensitive: $query->caseSensitive
                        ?? throw new LogicException('The case-sensitive state was not parsed.'),
                    description: $query->description ?? '',
                    language: $language,
                );

                return $this->responses->scalar($request, $dimensionId);
            }

            $this->dimensions->update(
                siteId: $siteId,
                dimensionId: $query->dimensionId
                    ?? throw new LogicException('The dimension ID was not parsed.'),
                name: $name,
                active: $active,
                extractions: $extractions,
                caseSensitive: $query->caseSensitive,
                description: $query->description,
                language: $language,
            );

            return $this->responses->success($request);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->responses->error($request, $exception->getMessage(), 400);
        }
    }
}
