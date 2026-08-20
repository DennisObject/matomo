<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ExampleUiReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class ExampleUiApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private ExampleUiReportBuilder $reports,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isExampleUiRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The ExampleUI API handler does not support this request.');
        }

        $parameters = $request->exampleUi
            ?? throw new LogicException('The ExampleUI request was not parsed.');
        $report = match ($request->method) {
            'ExampleUI.getTemperaturesEvolution' => $this->reports->evolution(
                $parameters->period
                    ?? throw new LogicException('The ExampleUI period was not parsed.'),
                $this->languages->resolve($httpRequest, $request->authentication),
            ),
            'ExampleUI.getTemperatures' => $this->reports->temperatures(),
            'ExampleUI.getPlanetRatios' => $this->reports->planets(false, $request->showMetadata),
            'ExampleUI.getPlanetRatiosWithLogos' => $this->reports->planets(true, $request->showMetadata),
            default => throw new LogicException('The ExampleUI API method is not implemented.'),
        };

        if ($request->format === 'rss') {
            return $this->responses->error(
                $request,
                "RSS feeds can be generated for one specific website &idSite=X.\n".
                    'Please specify only one idSite or consider using &format=XML instead.',
                200,
            );
        }

        return $this->responses->tableReport($request, $report);
    }
}
