<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\ImageGraph\ImageGraphRenderer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use RuntimeException;

final readonly class ImageGraphApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private ImageGraphRenderer $renderer,
        private Application $application,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->imageGraph !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->imageGraph
            ?? throw new LogicException('The ImageGraph API parameters are missing.');

        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->idSite)) {
            return $this->responses->error($request, 'You do not have view access to this site.', 401);
        }

        try {
            $rows = $this->reportRows($httpRequest, $parameters->apiModule.'.'.$parameters->apiAction);
            $png = $this->renderer->render(
                $rows,
                $parameters->columns,
                $parameters->graphType,
                $parameters->width,
                $parameters->height,
                $parameters->fontSize,
                $parameters->showLegend,
                $parameters->textColor,
                $parameters->backgroundColor,
                $parameters->gridColor,
            );
        } catch (RuntimeException $runtimeException) {
            return $this->responses->error($request, $runtimeException->getMessage(), 400);
        }

        if ($parameters->outputType === 1) {
            $filename = $this->filename($parameters->apiModule, $parameters->apiAction, $parameters->date, $parameters->idSite);
            $path = storage_path('app/image-graphs/'.$filename);
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
                return $this->responses->error($request, 'The graph directory could not be created.', 500);
            }

            if (file_put_contents($path, $png, LOCK_EX) === false) {
                return $this->responses->error($request, 'The graph file could not be written.', 500);
            }

            return $this->responses->scalar($request, $path);
        }

        return new Response($png, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($png)]);
    }

    /** @return list<array<string, mixed>> */
    private function reportRows(Request $request, string $method): array
    {
        if ($method === 'ImageGraph.get') {
            throw new RuntimeException('ImageGraph cannot use itself as a source report.');
        }

        $input = $request->all();
        unset($input['apiModule'], $input['apiAction'], $input['graphType'], $input['outputType'], $input['columns'],
            $input['labels'], $input['showLegend'], $input['width'], $input['height'], $input['fontSize'],
            $input['legendFontSize'], $input['aliasedGraph'], $input['colors'], $input['textColor'],
            $input['backgroundColor'], $input['gridColor'], $input['legendAppendMetric']);
        $input['module'] = 'API';
        $input['method'] = $method;
        $input['format'] = 'json';

        $nestedHttpRequest = Request::create('/index.php', 'GET', $input);
        $nestedApiRequest = ApiRequest::fromRequest($nestedHttpRequest);
        $response = $this->application->make(ApiMethodDispatcher::class)
            ->dispatch($nestedApiRequest, $nestedHttpRequest);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('The source report could not be loaded.');
        }

        $decoded = json_decode((string) $response->getContent(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The source report returned invalid data.');
        }

        $rows = [];
        $this->collectRows($decoded, $rows);

        return $rows;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectRows(array $value, array &$rows): void
    {
        if (! array_is_list($value) && array_filter($value, is_numeric(...)) !== []) {
            $row = [];
            foreach ($value as $name => $cell) {
                if (is_string($name)) {
                    $row[$name] = $cell;
                }
            }

            $rows[] = $row;

            return;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectRows($child, $rows);
            }
        }
    }

    private function filename(string $module, string $action, string $date, int $idSite): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', "{$module}_{$action}_{$date}_{$idSite}.png")
            ?? 'image-graph.png';
    }
}
