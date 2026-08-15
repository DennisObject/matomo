<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use RuntimeException;

final readonly class BulkApiMethodHandler implements ApiMethodHandler
{
    public function __construct(private Application $application, private ApiResponseFactory $responses) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->bulk !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $bulk = $request->bulk ?? throw new LogicException('The bulk API parameters are missing.');
        $limit = (int) config('matomo.api_bulk_request_limit', 250);
        if ($limit > -1 && count($bulk->urls) > $limit) {
            return $this->responses->error($request, "The maximum number of bulk request URLs is {$limit}.", 400);
        }

        $results = [];
        foreach ($bulk->urls as $url) {
            parse_str(ltrim($url, '?'), $nestedParameters);
            if (($nestedParameters['method'] ?? null) === 'API.getBulkRequest') {
                continue;
            }

            foreach (['token_auth', 'force_api_session'] as $authenticationParameter) {
                if ($httpRequest->query->has($authenticationParameter)) {
                    $nestedParameters[$authenticationParameter] = $httpRequest->query($authenticationParameter);
                }
            }

            $nestedParameters['module'] = 'API';
            $nestedParameters['format'] = 'json';
            $nestedRequest = Request::create('/index.php', 'GET', $nestedParameters);
            $nestedApiRequest = ApiRequest::fromRequest($nestedRequest);
            $response = $this->application->make(ApiMethodDispatcher::class)->dispatch($nestedApiRequest, $nestedRequest);
            $content = $response->getContent();
            if ($content === false) {
                throw new RuntimeException('A bulk API response body could not be read.');
            }

            $results[] = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->responses->structured($request, $results);
    }
}
