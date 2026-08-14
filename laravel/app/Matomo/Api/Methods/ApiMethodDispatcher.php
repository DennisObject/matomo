<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use Illuminate\Http\Response;
use LogicException;

final readonly class ApiMethodDispatcher
{
    /**
     * @param  list<ApiMethodHandler>  $handlers
     */
    public function __construct(private array $handlers) {}

    public function supports(ApiRequest $request): bool
    {
        return $this->handler($request) !== null;
    }

    public function dispatch(ApiRequest $request): Response
    {
        return $this->handler($request)?->handle($request)
            ?? throw new LogicException('The API method has not moved to Laravel yet.');
    }

    private function handler(ApiRequest $request): ?ApiMethodHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($request)) {
                return $handler;
            }
        }

        return null;
    }
}
