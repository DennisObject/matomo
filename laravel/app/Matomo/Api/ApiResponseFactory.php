<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use Illuminate\Http\Response;
use LogicException;

final class ApiResponseFactory
{
    public function success(ApiRequest $request, string $message = 'ok'): Response
    {
        return match ($request->format) {
            'console', 'rss' => $this->response(
                'Success:'.$message,
                200,
                $request->format === 'rss' ? 'text/xml; charset=utf-8' : 'text/plain; charset=utf-8',
            ),
            'csv', 'tsv' => $this->response(
                $request->format === 'csv' ? "message\n{$message}" : "message\t{$message}",
                200,
                'application/vnd.ms-excel',
            )->header('Content-Disposition', 'attachment; filename=piwik-report-export.csv'),
            'html' => $this->response("<!-- Success: {$message} -->", 200, 'text/html; charset=utf-8'),
            'json' => $this->json($request, ['result' => 'success', 'message' => $message], 200),
            'original' => $this->response('1', 200, 'text/plain; charset=utf-8'),
            'xml' => $this->response(
                "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<success message=\"".
                    $this->escape($message)."\" />\n</result>",
                200,
                'text/xml; charset=utf-8',
            ),
            default => throw new LogicException('The API response format is not supported.'),
        };
    }

    public function scalar(ApiRequest $request, bool|int|string $value): Response
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

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    public function row(ApiRequest $request, array $values): Response
    {
        return match ($request->format) {
            'console' => $this->consoleRow($request, $values),
            'csv', 'tsv' => $this->spreadsheetRow($request, $values),
            'html' => $this->htmlRow($values),
            'json' => $this->json($request, $values, 200),
            'original' => $this->originalRow($request, $values),
            'rss' => $this->rssScalarError(),
            'xml' => $this->xmlRow($values),
            default => throw new LogicException('The API response format is not supported.'),
        };
    }

    /**
     * @param  list<int|string>  $values
     */
    public function values(ApiRequest $request, array $values): Response
    {
        return match ($request->format) {
            'console' => $this->consoleValues($request, $values),
            'csv', 'tsv' => $this->spreadsheetValues($request, $values),
            'html' => $this->htmlValues($values),
            'json' => $this->jsonValues($request, $values),
            'original' => $this->originalValues($request, $values),
            'rss' => $this->rssScalarError(),
            'xml' => $this->xmlValues($values),
            default => throw new LogicException('The API response format is not supported.'),
        };
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    public function rows(ApiRequest $request, array $rows): Response
    {
        return match ($request->format) {
            'console' => $this->consoleRows($request, $rows),
            'csv', 'tsv' => $this->spreadsheetRows($request, $rows),
            'html' => $this->htmlRows($rows),
            'json' => $this->jsonRows($request, $rows),
            'original' => $this->originalRows($request, $rows),
            'rss' => $this->rssScalarError(),
            'xml' => $this->xmlRows($rows),
            default => throw new LogicException('The API response format is not supported.'),
        };
    }

    /**
     * @param  array<int, array<string, bool|int|string|null>>  $rows
     */
    public function keyedRows(ApiRequest $request, array $rows): Response
    {
        return match ($request->format) {
            'console' => $this->consoleRows($request, array_values($rows)),
            'csv', 'tsv' => $this->spreadsheetRows($request, array_values($rows)),
            'html' => $this->htmlRows(array_values($rows)),
            'json' => $this->jsonStructured($request, $rows),
            'original' => $this->originalStructured($request, $rows),
            'rss' => $this->rssScalarError(),
            'xml' => $this->xmlStructured($rows),
            default => throw new LogicException('The API response format is not supported.'),
        };
    }

    /**
     * @param  array<string, array<string, string>>  $values
     */
    public function structured(ApiRequest $request, array $values): Response
    {
        return match ($request->format) {
            'json' => $this->jsonStructured($request, $values),
            'original' => $this->originalStructured($request, $values),
            'xml' => $this->xmlStructured($values),
            'console', 'csv', 'html', 'rss', 'tsv' => $this->error(
                $request,
                $this->nestedArrayFormatError($values),
                500,
            ),
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

    private function xmlScalar(bool|int|string $value): Response
    {
        $value = $this->scalarText($value, false);

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>{$this->escape($value)}</result>",
            200,
            'text/xml; charset=utf-8',
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    private function xmlRow(array $values): Response
    {
        $columns = '';

        foreach ($values as $name => $value) {
            $value = $this->scalarText($value, false);
            $columns .= $value === ''
                ? "\n\t\t<{$name} />"
                : "\n\t\t<{$name}>{$this->escape($value)}</{$name}>";
        }

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<row>{$columns}\n\t</row>\n</result>",
            200,
            'text/xml; charset=utf-8',
        );
    }

    /**
     * @param  list<int|string>  $values
     */
    private function xmlValues(array $values): Response
    {
        if ($values === []) {
            return $this->response(
                "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result />",
                200,
                'text/xml; charset=utf-8',
            );
        }

        $rows = '';

        foreach ($values as $value) {
            $rows .= "\n\t<row>{$this->escape((string) $value)}</row>";
        }

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>{$rows}\n</result>",
            200,
            'text/xml; charset=utf-8',
        );
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    private function xmlRows(array $rows): Response
    {
        if ($rows === []) {
            return $this->response(
                "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result />",
                200,
                'text/xml; charset=utf-8',
            );
        }

        $content = '';

        foreach ($rows as $row) {
            $content .= "\n\t<row>\n";
            $content .= $this->xmlArray($row, "\t\t");
            $content .= "\t</row>";
        }

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>{$content}\n</result>",
            200,
            'text/xml; charset=utf-8',
        );
    }

    /**
     * @param  array<array-key, array<string, bool|int|string|null>>  $values
     */
    private function xmlStructured(array $values): Response
    {
        if ($values === []) {
            return $this->response(
                "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result />",
                200,
                'text/xml; charset=utf-8',
            );
        }

        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
                $this->xmlArray($values, "\t").'</result>',
            200,
            'text/xml; charset=utf-8',
        );
    }

    /**
     * @param  array<array-key, array<array-key, bool|int|string|null>|bool|int|string|null>  $values
     */
    private function xmlArray(array $values, string $indent): string
    {
        $xml = '';

        foreach ($values as $key => $value) {
            [$prefix, $suffix, $empty] = $this->xmlArrayTags((string) $key);

            if (is_array($value)) {
                $xml .= $indent.$prefix."\n";
                $xml .= $this->xmlArray($value, $indent."\t");
                $xml .= $indent.$suffix."\n";

                continue;
            }

            $value = $this->scalarText($value, false);
            $xml .= $value === ''
                ? $indent.$empty."\n"
                : $indent.$prefix.$this->escape($value).$suffix."\n";
        }

        return $xml;
    }

    /**
     * @return array{string, string, string}
     */
    private function xmlArrayTags(string $key): array
    {
        if (str_contains($key, '=')) {
            [$attribute, $value] = explode('=', $key, 2);

            return [
                '<row '.$attribute.'="'.$this->escape($value).'">',
                '</row>',
                '<row '.$attribute.'="'.$this->escape($value).'">',
            ];
        }

        if (! $this->validXmlArrayTag($key)) {
            return [
                '<row key="'.$this->escape($key).'">',
                '</row>',
                '<row key="'.$this->escape($key).'"/>',
            ];
        }

        return ["<{$key}>", "</{$key}>", "<{$key} />"];
    }

    private function validXmlArrayTag(string $key): bool
    {
        $invalidCharacters = "!\"#$%&'()*+,\\/;<=>?@[\\]\\\\^`{|}~";
        $invalidStartCharacters = $invalidCharacters.'\\-.0123456789';

        return preg_match(
            "/^[^{$invalidStartCharacters}][^{$invalidCharacters}]*$/D",
            $key,
        ) === 1;
    }

    private function xmlError(string $message, int $status): Response
    {
        return $this->response(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<error message=\"{$this->escape($message)}\" />\n</result>",
            $status,
            'text/xml; charset=utf-8',
        );
    }

    private function spreadsheet(ApiRequest $request, bool|int|string $value): Response
    {
        $value = $this->spreadsheetCell($this->scalarText($value, false), ',');
        $content = "value\n{$value}";

        if ($request->convertToUnicode && function_exists('mb_convert_encoding')) {
            $content = "\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
        }

        return $this->response($content, 200, 'application/vnd.ms-excel')->header(
            'Content-Disposition',
            "attachment; filename*=UTF-8''Export",
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    private function spreadsheetRow(ApiRequest $request, array $values): Response
    {
        $delimiter = $request->format === 'csv' ? ',' : "\t";
        $header = implode($delimiter, array_map(
            fn (string $value): string => $this->spreadsheetCell($value, $delimiter),
            array_keys($values),
        ));
        $row = implode($delimiter, array_map(
            fn (bool|int|string|null $value): string => $this->spreadsheetCell(
                $this->scalarText($value, false),
                $delimiter,
            ),
            array_values($values),
        ));
        $content = $header."\n".$row;

        if ($request->convertToUnicode && function_exists('mb_convert_encoding')) {
            $content = "\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
        }

        return $this->response($content, 200, 'application/vnd.ms-excel')->header(
            'Content-Disposition',
            "attachment; filename*=UTF-8''Export",
        );
    }

    /**
     * @param  list<int|string>  $values
     */
    private function spreadsheetValues(ApiRequest $request, array $values): Response
    {
        $content = $values === []
            ? 'No data available'
            : implode("\n", array_map(
                fn (int|string $value): string => $this->spreadsheetCell((string) $value, ','),
                $values,
            ));

        if ($request->convertToUnicode && function_exists('mb_convert_encoding')) {
            $content = "\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
        }

        return $this->response($content, 200, 'application/vnd.ms-excel')->header(
            'Content-Disposition',
            "attachment; filename*=UTF-8''Export",
        );
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    private function spreadsheetRows(ApiRequest $request, array $rows): Response
    {
        if ($rows === []) {
            $content = 'No data available';
        } else {
            $rows = array_map($this->flattenNestedRow(...), $rows);
            $delimiter = $request->format === 'csv' ? ',' : "\t";
            $columns = $this->rowColumns($rows);
            $lines = [implode($delimiter, array_map(
                fn (string $column): string => $this->spreadsheetCell($column, $delimiter),
                $columns,
            ))];

            foreach ($rows as $row) {
                $lines[] = implode($delimiter, array_map(
                    fn (string $column): string => $this->spreadsheetCell(
                        $this->scalarText($row[$column] ?? '', false),
                        $delimiter,
                    ),
                    $columns,
                ));
            }

            $content = implode("\n", $lines);
        }

        if ($request->convertToUnicode && function_exists('mb_convert_encoding')) {
            $content = "\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
        }

        return $this->response($content, 200, 'application/vnd.ms-excel')->header(
            'Content-Disposition',
            "attachment; filename*=UTF-8''Export",
        );
    }

    private function spreadsheetCell(string $value, string $delimiter): string
    {
        if (! is_numeric($value)) {
            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        }

        $value = $this->spreadsheetFormulaSafe($value);
        $value = str_replace(["\t", "\r"], ' ', $value);

        if (! str_contains($value, $delimiter) && strpbrk($value, ",;\"\n") === false) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    private function spreadsheetFormulaSafe(string $value): string
    {
        $probe = ltrim($value, "\0");

        while (str_starts_with($probe, '%00')) {
            $probe = ltrim(substr($probe, 3), "\0");
        }

        $percent = strpos($probe, '%');

        if ($percent !== false) {
            $probe = substr_replace($probe, '', $percent, 1);
        }

        if ($probe !== '' && ! is_numeric($probe) && in_array($probe[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function html(ApiRequest $request, bool|int|string $value): Response
    {
        $id = $this->escape(str_replace('.', '_', $request->method));
        $value = $this->escape($this->scalarText($value, false));
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

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    private function htmlRow(array $values): Response
    {
        $headers = '';
        $cells = '';

        foreach ($values as $name => $value) {
            $headers .= "\n\t\t<th>{$this->escape($name)}</th>";
            $cells .= "\n\t\t<td>{$this->escape($this->scalarText($value, false))}</td>";
        }

        $content = <<<HTML
        <table border="1">
        <thead>
        \t<tr>{$headers}
        \t</tr>
        </thead>
        <tbody>
        \t<tr>{$cells}
        \t</tr>
        </tbody>
        </table>

        HTML;

        return $this->response($content, 200, 'text/html; charset=utf-8');
    }

    /**
     * @param  list<int|string>  $values
     */
    private function htmlValues(array $values): Response
    {
        if ($values === []) {
            return $this->response(
                "<table border=\"1\">\n<thead>\n\t<tr>\n\t</tr>\n</thead>\n".
                    "<tbody>\n</tbody>\n</table>\n",
                200,
                'text/html; charset=utf-8',
            );
        }

        $rows = '';

        foreach ($values as $value) {
            $rows .= "\n\t<tr>\n\t\t<td>{$this->escape((string) $value)}</td>\n\t</tr>";
        }

        $content = <<<HTML
        <table border="1">
        <thead>
        \t<tr>
        \t\t<th>value</th>
        \t</tr>
        </thead>
        <tbody>{$rows}
        </tbody>
        </table>

        HTML;

        return $this->response($content, 200, 'text/html; charset=utf-8');
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    private function htmlRows(array $rows): Response
    {
        $headers = '';
        $body = '';
        $columns = $this->rowColumns($rows);

        foreach ($columns as $column) {
            $headers .= "\n\t\t<th>{$this->escape($column)}</th>";
        }

        foreach ($rows as $row) {
            $body .= "\n\t<tr>";

            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                $value = is_array($value)
                    ? '<pre>'.$this->escape(var_export($value, true)).'</pre>'
                    : $this->escape($this->scalarText($value, false));
                $body .= "\n\t\t<td>{$value}</td>";
            }

            $body .= "\n\t</tr>";
        }

        $content = <<<HTML
        <table border="1">
        <thead>
        \t<tr>{$headers}
        \t</tr>
        </thead>
        <tbody>{$body}
        </tbody>
        </table>

        HTML;

        return $this->response($content, 200, 'text/html; charset=utf-8');
    }

    private function original(ApiRequest $request, bool|int|string $value): Response
    {
        return $this->response(
            $request->serialize ? serialize($value) : $this->scalarText($value, true),
            200,
            'text/plain; charset=utf-8',
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    private function originalRow(ApiRequest $request, array $values): Response
    {
        return $this->response(
            $request->serialize ? serialize($values) : var_export($values, true),
            200,
            'text/plain; charset=utf-8',
        );
    }

    /**
     * @param  list<int|string>  $values
     */
    private function originalValues(ApiRequest $request, array $values): Response
    {
        return $this->response(
            $request->serialize ? serialize($values) : var_export($values, true),
            200,
            'text/plain; charset=utf-8',
        );
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    private function originalRows(ApiRequest $request, array $rows): Response
    {
        return $this->response(
            $request->serialize ? serialize($rows) : var_export($rows, true),
            200,
            'text/plain; charset=utf-8',
        );
    }

    /**
     * @param  array<array-key, array<string, bool|int|string|null>>  $values
     */
    private function originalStructured(ApiRequest $request, array $values): Response
    {
        return $this->response(
            $request->serialize ? serialize($values) : var_export($values, true),
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

    private function console(ApiRequest $request, bool|int|string $value): Response
    {
        $value = is_string($value)
            ? "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'"
            : $this->scalarText($value, true);
        $metadata = $request->showMetadata ? ' [] [idsubtable = ]' : '';

        return $this->response(
            "- 1 ['0' => {$value}]{$metadata}<br />\n",
            200,
            'text/plain; charset=utf-8',
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    private function consoleRow(ApiRequest $request, array $values): Response
    {
        $columns = [];

        foreach ($values as $name => $value) {
            $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
            $renderedValue = is_string($value)
                ? "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'"
                : $this->scalarText($value, true);
            $columns[] = "'{$escapedName}' => {$renderedValue}";
        }

        $metadata = $request->showMetadata ? ' [] [idsubtable = ]' : '';

        return $this->response(
            '- 1 ['.implode(', ', $columns)."]{$metadata}<br />\n",
            200,
            'text/plain; charset=utf-8',
        );
    }

    /**
     * @param  list<int|string>  $values
     */
    private function consoleValues(ApiRequest $request, array $values): Response
    {
        if ($values === []) {
            return $this->response("Empty table<br />\n", 200, 'text/plain; charset=utf-8');
        }

        $metadata = $request->showMetadata ? ' [] [idsubtable = ]' : '';
        $rows = '';

        foreach ($values as $index => $value) {
            $renderedValue = is_int($value)
                ? (string) $value
                : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
            $rows .= '- '.($index + 1)." ['0' => {$renderedValue}]{$metadata}<br />\n";
        }

        return $this->response($rows, 200, 'text/plain; charset=utf-8');
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    private function consoleRows(ApiRequest $request, array $rows): Response
    {
        if ($rows === []) {
            return $this->response("Empty table<br />\n", 200, 'text/plain; charset=utf-8');
        }

        $output = '';
        $metadata = $request->showMetadata ? ' [] [idsubtable = ]' : '';

        foreach ($rows as $index => $row) {
            $columns = [];

            foreach ($row as $name => $value) {
                $name = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
                $value = match (true) {
                    is_array($value) => var_export($value, true),
                    is_string($value) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
                    default => $this->scalarText($value, true),
                };
                $columns[] = "'{$name}' => {$value}";
            }

            $number = $index + 1;
            $output .= "- {$number} [".implode(', ', $columns)."]{$metadata}<br />\n";
        }

        return $this->response($output, 200, 'text/plain; charset=utf-8');
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
     * @param  array<string, bool|int|string|null>  $payload
     */
    private function json(ApiRequest $request, array $payload, int $status): Response
    {
        return $this->jsonEncoded($request, json_encode($payload, JSON_THROW_ON_ERROR), $status);
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     */
    private function jsonRows(ApiRequest $request, array $rows): Response
    {
        return $this->jsonEncoded($request, json_encode($rows, JSON_THROW_ON_ERROR), 200);
    }

    /**
     * @param  array<array-key, array<string, bool|int|string|null>>  $values
     */
    private function jsonStructured(ApiRequest $request, array $values): Response
    {
        return $this->jsonEncoded($request, json_encode($values, JSON_THROW_ON_ERROR), 200);
    }

    private function jsonEncoded(ApiRequest $request, string $json, int $status): Response
    {
        if ($request->callback !== null && preg_match('/^[0-9a-zA-Z_.]*$/D', $request->callback) === 1) {
            return $this->response(
                $request->callback.'('.$json.')',
                $status,
                'application/javascript; charset=utf-8',
            );
        }

        return $this->response($json, $status, 'application/json; charset=utf-8');
    }

    /**
     * @param  array<string, array<string, string>>  $values
     */
    private function nestedArrayFormatError(array $values): string
    {
        $key = array_key_first($values);

        if ($key === null) {
            return 'Data structure returned is not convertible in the requested format.';
        }

        $row = substr(var_export($values[$key], true), 0, 500);

        return 'Data structure returned is not convertible in the requested format: '.
            "Only integer keys supported for array columns on base level. Unsupported string '{$key}' ".
            "found for row '{$row}'. Try to call this method with the parameters ".
            "'&format=original&serialize=1'; you will get the original php data structure serialized.";
    }

    private function scalarText(bool|int|string|null $value, bool $emptyFalse): string
    {
        if ($value === null) {
            return '';
        }

        if ($value === false) {
            return $emptyFalse ? '' : '0';
        }

        return (string) $value;
    }

    /**
     * @param  list<array<string, array<int, string>|bool|int|string|null>>  $rows
     * @return list<string>
     */
    private function rowColumns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    /**
     * @param  array<array-key, array<array-key, string>|bool|int|string|null>  $row
     * @return array<string, bool|int|string|null>
     */
    private function flattenNestedRow(array $row, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($row as $name => $value) {
            $name = $prefix === '' ? (string) $name : $prefix.'_'.(string) $name;

            if (is_array($value)) {
                $flattened = [...$flattened, ...$this->flattenNestedRow($value, $name)];
            } else {
                $flattened[$name] = $value;
            }
        }

        return $flattened;
    }

    /**
     * @param  list<int|string>  $values
     */
    private function jsonValues(ApiRequest $request, array $values): Response
    {
        $json = json_encode($values, JSON_THROW_ON_ERROR);

        if ($request->callback !== null && preg_match('/^[0-9a-zA-Z_.]*$/D', $request->callback) === 1) {
            return $this->response(
                $request->callback.'('.$json.')',
                200,
                'application/javascript; charset=utf-8',
            );
        }

        return $this->response($json, 200, 'application/json; charset=utf-8');
    }

    private function response(string $content, int $status, string $contentType): Response
    {
        return new Response($content, $status, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
