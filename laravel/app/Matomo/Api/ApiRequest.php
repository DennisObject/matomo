<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use Illuminate\Http\Request;

final readonly class ApiRequest
{
    private function __construct(
        public string $module,
        public string $method,
        public string $format,
        public ?string $callback,
        #[\SensitiveParameter]
        public ?string $token,
        public bool $tokenIsSecure,
    ) {}

    public static function fromRequest(Request $request): self
    {
        [$token, $tokenIsSecure] = self::authentication($request);

        return self::make($request, $token, $tokenIsSecure);
    }

    public static function withoutAuthentication(Request $request): self
    {
        return new self(
            module: '',
            method: '',
            format: strtolower(self::safeStringInput($request, 'format', 'xml')),
            callback: self::safeNullableStringInput($request, 'callback')
                ?? self::safeNullableStringInput($request, 'jsoncallback'),
            token: null,
            tokenIsSecure: false,
        );
    }

    public function isVersionRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, ['API.getMatomoVersion', 'API.getPiwikVersion'], true);
    }

    public function hasSupportedFormat(): bool
    {
        return in_array($this->format, ['json', 'xml'], true);
    }

    private static function make(Request $request, ?string $token, bool $tokenIsSecure): self
    {
        return new self(
            module: self::stringInput($request, 'module'),
            method: self::stringInput($request, 'method'),
            format: strtolower(self::stringInput($request, 'format', 'xml')),
            callback: self::nullableStringInput($request, 'callback')
                ?? self::nullableStringInput($request, 'jsoncallback'),
            token: $token,
            tokenIsSecure: $tokenIsSecure,
        );
    }

    /**
     * @return array{string|null, bool}
     */
    private static function authentication(Request $request): array
    {
        $authorization = $request->headers->get('Authorization');
        $headerToken = is_string($authorization) && str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7)
            : null;
        $postToken = self::stringFromArray($request->request->all(), 'token_auth');
        $queryToken = self::stringFromArray($request->query->all(), 'token_auth');

        $providedTokens = array_filter(
            [$headerToken, $postToken, $queryToken],
            static fn (?string $token): bool => ! empty($token),
        );

        if (count(array_unique($providedTokens)) > 1) {
            throw new ConflictingAuthenticationParameters;
        }

        if ($headerToken !== null) {
            return [$headerToken, true];
        }

        if ($postToken !== null && $postToken !== '') {
            return [$postToken, true];
        }

        return [$queryToken, false];
    }

    private static function stringInput(Request $request, string $key, string $default = ''): string
    {
        return self::nullableStringInput($request, $key) ?? $default;
    }

    private static function nullableStringInput(Request $request, string $key): ?string
    {
        $queryValue = self::stringFromArray($request->query->all(), $key);

        return $queryValue ?? self::stringFromArray($request->request->all(), $key);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function stringFromArray(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];

        if (! is_scalar($value)) {
            throw new InvalidApiParameter($key);
        }

        return str_replace("\0", '', (string) $value);
    }

    private static function safeStringInput(Request $request, string $key, string $default = ''): string
    {
        return self::safeNullableStringInput($request, $key) ?? $default;
    }

    private static function safeNullableStringInput(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? $request->request->all()[$key] ?? null;

        return is_scalar($value) ? str_replace("\0", '', (string) $value) : null;
    }
}
