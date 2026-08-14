<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use Illuminate\Http\Response;
use LogicException;

final class ApiResponseFactory
{
    public function scalar(ApiRequest $request, string $value): Response
    {
        return match ($request->format) {
            'console' => $this->console($request, $value),
            'csv', 'tsv' => $this->spreadsheet($request, $value),
            'html' => $this->html($request, $value),
            'json' => $this->json($request, ['value' => $value], 200),
            'original' => $this->original($request, $value),
            'rss' => $this->rssScalarError(),
            'xml' => $this->xmlScalar($value),
            default => throw new LogicException('The API response format is not supported.'),
        };
    }

    public function error(ApiRequest $request, string $message, int $status): Response
    {
        return match ($request->format) {
            'json' => $this->json($request, [
                'result' => 'error',
                'message' => $message,
            ], $status),
            'xml' => $this->xmlError($message, $status),
            'csv', 'tsv' => $this->response('Error: '.$this->escape($message), $status, 'text/html; charset=utf-8'),
            'html' => $this->response(nl2br($this->escape($message)), $status, 'text/plain; charset=utf-8'),
            'original' => $this->originalError($request, $message, $status),
            'console', 'rss' => $this->response('Error: '.$this->escape($message), $status, 'text/plain; charset=utf-8'),
            default => $this->xmlError($message, $status),
        };
    }

    private function xmlScalar(string $value): Response
    {
        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>{$this->escape($value)}</result>",
            200,
            'text/xml; charset=utf-8',
        );
    }

    private function xmlError(string $message, int $status): Response
    {
        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<error message=\"{$this->escape($message)}\" />\n</result>",
            $status,
            'text/xml; charset=utf-8',
        );
    }

    private function spreadsheet(ApiRequest $request, string $value): Response
    {
        $content = "value\n{$value}";

        if ($request->convertToUnicode && function_exists('mb_convert_encoding')) {
            $content = "\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
        }

        return $this->response($content, 200, 'application/vnd.ms-excel')->header(
            'Content-Disposition',
            "attachment; filename*=UTF-8''Export",
        );
    }

    private function html(ApiRequest $request, string $value): Response
    {
        $id = $this->escape(str_replace('.', '_', $request->method));
        $value = $this->escape($value);
        $content = <<<HTML
        <table id="{$id}" border="1">
        <thead>
        \t<tr>
        \t\t<th>value</th>
        \t</tr>
        </thead>
        <tbody>
        \t<tr>
        \t\t<td>{$value}</td>
        \t</tr>
        </tbody>
        </table>

        HTML;

        return $this->response($content, 200, 'text/html; charset=utf-8');
    }

    private function original(ApiRequest $request, string $value): Response
    {
        return $this->response(
            $request->serialize ? serialize($value) : $value,
            200,
            'text/plain; charset=utf-8',
        );
    }

    private function originalError(ApiRequest $request, string $message, int $status): Response
    {
        $content = $request->serialize
            ? serialize(['result' => 'error', 'message' => $this->escape($message)])
            : 'Error: '.$this->escape($message);

        return $this->response($content, $status, 'text/plain; charset=utf-8');
    }

    private function console(ApiRequest $request, string $value): Response
    {
        $value = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        $metadata = $request->showMetadata ? ' [] [idsubtable = ]' : '';

        return $this->response(
            "- 1 ['0' => '{$value}']{$metadata}<br />\n",
            200,
            'text/plain; charset=utf-8',
        );
    }

    private function rssScalarError(): Response
    {
        return $this->response(
            "Error: RSS feeds can be generated for one specific website &idSite=X.\n".
                'Please specify only one idSite or consider using &format=XML instead.',
            200,
            'text/plain; charset=utf-8',
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
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
