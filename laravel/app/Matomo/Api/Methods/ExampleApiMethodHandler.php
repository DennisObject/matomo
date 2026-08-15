<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Piwik\Version;
use stdClass;

final readonly class ExampleApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isExampleApiRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The ExampleAPI handler does not support this request.');
        }

        if ($request->method === 'ExampleAPI.getMatomoVersion') {
            if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    'You must have view access to at least one website.',
                    401,
                );
            }

            return $this->responses->scalar($request, Version::VERSION);
        }

        return match ($request->method) {
            'ExampleAPI.getAnswerToLife' => $this->responses->scalar($request, 42),
            'ExampleAPI.getObject' => $this->responses->object($request, $this->magicObject()),
            'ExampleAPI.getSum' => $this->sum($request),
            'ExampleAPI.getNull' => $this->responses->success($request),
            'ExampleAPI.getDescriptionArray' => $this->responses->values($request, [
                'piwik',
                'free/libre',
                'web analytics',
                'free',
                'Strong message: Свободный Тибет',
            ]),
            'ExampleAPI.getCompetitionDatatable' => $this->responses->tableReport(
                $request,
                new ApiTableReport([
                    [
                        'name' => 'piwik',
                        'license' => 'GPL',
                        ...($request->showMetadata ? ['logo' => 'logo.png'] : []),
                    ],
                    ['name' => 'google analytics', 'license' => 'commercial'],
                ], []),
            ),
            'ExampleAPI.getMoreInformationAnswerToLife' => $this->responses->scalar(
                $request,
                'Check http://en.wikipedia.org/wiki/The_Answer_to_Life,_the_Universe,_and_Everything',
            ),
            'ExampleAPI.getMultiArray' => $this->multiArray($request),
            default => throw new LogicException('The ExampleAPI method is not implemented.'),
        };
    }

    private function sum(ApiRequest $request): Response
    {
        $parameters = $request->exampleApi
            ?? throw new LogicException('The ExampleAPI sum request was not parsed.');

        return $this->responses->scalar($request, $parameters->a + $parameters->b);
    }

    private function magicObject(): stdClass
    {
        $object = new stdClass;
        $object->great = 'formidable';

        return $object;
    }

    private function multiArray(ApiRequest $request): Response
    {
        $values = [
            'Limitation' => [
                'Multi dimensional arrays is only supported by format=JSON',
                'Known limitation',
            ],
            'Second Dimension' => [true, false, 1, 0, 152, 'test', [42 => 'end']],
        ];

        return in_array($request->format, ['json', 'original'], true)
            ? $this->responses->structured($request, $values)
            : $this->responses->unsupportedStructure($request, $values);
    }
}
