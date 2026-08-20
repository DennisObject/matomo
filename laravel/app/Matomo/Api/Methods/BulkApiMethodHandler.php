<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\BulkRequestLimit;
use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Authentication\ApiAuthentication;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use RuntimeException;

final readonly class BulkApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private Application $application,
        private ApiResponseFactory $responses,
        private BulkRequestLimit $requestLimit,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->bulk !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $bulk = $request->bulk ?? throw new LogicException('The bulk API parameters are missing.');
        $limit = $this->requestLimit->current($request->authentication);
        if ($limit > -1 && count($bulk->urls) > $limit) {
            return $this->responses->error($request, "The maximum number of bulk request URLs is {$limit}.", 400);
        }

        $outerParameters = array_replace($httpRequest->request->all(), $httpRequest->query->all());
        unset(
            $outerParameters['urls'],
            $outerParameters['token_auth'],
            $outerParameters['force_api_session'],
            $outerParameters['callback'],
            $outerParameters['jsoncallback'],
        );

        $results = [];
        foreach ($bulk->urls as $url) {
            $nestedParameters = $this->parseQuery($url);
            $authenticationError = null;

            try {
                $authentication = $this->nestedAuthentication($request->authentication, $nestedParameters);
            } catch (ConflictingAuthenticationParameters $conflict) {
                return $this->responses->error($request, $conflict->getMessage(), 400);
            } catch (InvalidApiParameter $invalidAuthentication) {
                $authentication = $request->authentication;
                $authenticationError = $invalidAuthentication;
            }

            unset(
                $nestedParameters['token_auth'],
                $nestedParameters['force_api_session'],
                $nestedParameters['callback'],
                $nestedParameters['jsoncallback'],
            );
            $nestedParameters += $outerParameters;
            if ($this->isRecursive($nestedParameters['method'] ?? null)) {
                continue;
            }

            $nestedParameters['module'] = 'API';
            $nestedParameters['format'] = 'json';
            $nestedRequest = Request::create('/index.php', 'GET', $nestedParameters);

            if ($authenticationError !== null) {
                $response = $this->responses->error(
                    ApiRequest::withoutAuthentication($nestedRequest),
                    $authenticationError->getMessage(),
                    400,
                );
                $results[] = $this->decodedContent($response);

                continue;
            }

            try {
                $nestedApiRequest = ApiRequest::fromRequestWithAuthentication(
                    $nestedRequest,
                    $authentication,
                );
            } catch (InvalidApiParameter|MissingApiParameter $invalidRequest) {
                $response = $this->responses->error(
                    ApiRequest::withoutAuthentication($nestedRequest),
                    $invalidRequest->getMessage(),
                    400,
                );
                $results[] = $this->decodedContent($response);

                continue;
            }

            $dispatcher = $this->application->make(ApiMethodDispatcher::class);
            $response = $dispatcher->supports($nestedApiRequest)
                ? $dispatcher->dispatch($nestedApiRequest, $nestedRequest)
                : $this->responses->error(
                    $nestedApiRequest,
                    'This API method has not moved to Laravel yet.',
                    501,
                );
            $results[] = $this->decodedContent($response);
        }

        return $this->responses->structured($request, $results);
    }

    /** @return array<array-key, mixed> */
    private function parseQuery(string $url): array
    {
        $query = ltrim($url, '?');
        parse_str($query, $parameters);

        if (count($parameters) === 1 && end($parameters) === '') {
            parse_str(urldecode($query), $decodedParameters);

            return $decodedParameters;
        }

        return $parameters;
    }

    private function isRecursive(mixed $method): bool
    {
        if (! is_scalar($method)) {
            return false;
        }

        $method = preg_replace('/[^\w.]+/', '', str_replace("\0", '', (string) $method));

        return $method === 'API.getBulkRequest';
    }

    /** @param array<array-key, mixed> $parameters */
    private function nestedAuthentication(
        ApiAuthentication $outerAuthentication,
        array $parameters,
    ): ApiAuthentication {
        if (array_key_exists('force_api_session', $parameters)
            && $this->boolean($parameters['force_api_session']) !== $outerAuthentication->forceSession) {
            throw new ConflictingAuthenticationParameters;
        }

        if (! array_key_exists('token_auth', $parameters)) {
            return $outerAuthentication;
        }

        $token = $parameters['token_auth'];
        if (! is_scalar($token) && $token !== null) {
            throw new InvalidApiParameter('token_auth');
        }

        $token = str_replace("\0", '', (string) ($token ?? ''));

        if ($outerAuthentication->forceSession) {
            if ($token !== $outerAuthentication->token) {
                throw new ConflictingAuthenticationParameters;
            }

            return $outerAuthentication;
        }

        return new ApiAuthentication(
            token: $token,
            tokenIsSecure: false,
            forceSession: false,
            sessionId: $outerAuthentication->sessionId,
        );
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true)
            || (is_string($value) && strtolower($value) === 'true');
    }

    private function decodedContent(Response $response): mixed
    {
        $content = $response->getContent();
        if ($content === false) {
            throw new RuntimeException('A bulk API response body could not be read.');
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
