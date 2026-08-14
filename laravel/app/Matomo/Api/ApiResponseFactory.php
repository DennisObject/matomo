<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use Illuminate\Http\Response;

final class ApiResponseFactory
{
    public function scalar(ApiRequest $request, string $value): Response
    {
        if ($request->format === 'json') {
            return $this->json($request, ['value' => $value], 200);
        }

        $value = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>{$value}</result>",
            200,
            'text/xml; charset=utf-8',
        );
    }

    public function error(ApiRequest $request, string $message, int $status): Response
    {
        if ($request->format === 'json') {
            return $this->json($request, [
                'result' => 'error',
                'message' => $message,
            ], $status);
        }

        $message = htmlspecialchars($message, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<error message=\"{$message}\" />\n</result>",
            $status,
            'text/xml; charset=utf-8',
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function json(ApiRequest $request, array $payload, int $status): Response
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($request->callback !== null && preg_match('/^[0-9a-zA-Z_.]*$/D', $request->callback) === 1) {
            return $this->response(
                $request->callback.'('.$json.')',
                $status,
                'application/javascript; charset=utf-8',
            );
        }

        return $this->response($json, $status, 'application/json; charset=utf-8');
    }

    private function response(string $content, int $status, string $contentType): Response
    {
        return new Response($content, $status, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
