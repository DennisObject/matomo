<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\ProfessionalServices\PromoWidgetDismissalRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class ProfessionalServicesApiMethodHandler implements ApiMethodHandler
{
    /** @var list<string> */
    private const array WIDGETS = [
        'PromoAbTesting',
        'PromoCrashAnalytics',
        'PromoCustomReports',
        'PromoFormAnalytics',
        'PromoFunnels',
        'PromoHeatmaps',
        'PromoMediaAnalytics',
        'PromoSessionRecordings',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private PromoWidgetDismissalRepository $dismissals,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isProfessionalServicesRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The ProfessionalServices API handler does not support this request.');
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if (in_array($login, [null, '', 'anonymous'], true)) {
            return $this->responses->error(
                $request,
                'You must be logged in to access this functionality.',
                401,
            );
        }

        $widgetName = $request->widgetName ?? '';

        if (! in_array($widgetName, self::WIDGETS, true)) {
            return $this->responses->error($request, "Can't dismiss unknown widget {$widgetName}", 400);
        }

        $this->dismissals->dismiss($login, $widgetName, time());

        return $this->responses->scalar($request, true);
    }
}
