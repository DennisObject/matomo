<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use Illuminate\Http\Request;

final readonly class ApiLanguageResolver implements LanguageResolver
{
    /**
     * @param  list<string>  $availableLanguages
     */
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private LanguagePreferenceRepository $preferences,
        private array $availableLanguages,
        private string $defaultLanguage,
        private string $cookieName,
        #[\SensitiveParameter]
        private string $salt,
    ) {}

    public function resolve(Request $request, ApiAuthentication $authentication): string
    {
        $requested = $this->requestLanguage($request);

        if ($requested !== null && $requested !== '') {
            return $this->isAvailable($requested) ? $requested : $this->fallback();
        }

        $cookie = $this->cookieLanguage($request);

        if ($cookie !== null && $this->isAvailable($cookie)) {
            return $cookie;
        }

        $login = $this->authorizer->authenticatedLogin($authentication);
        $preference = $login === null ? null : $this->preferences->forLogin($login);

        if ($preference !== null && $this->isAvailable($preference)) {
            return $preference;
        }

        foreach ($request->getLanguages() as $language) {
            $language = strtolower(str_replace('_', '-', $language));

            if ($this->isAvailable($language)) {
                return $language;
            }

            $shortLanguage = substr($language, 0, 2);

            if ($this->isAvailable($shortLanguage)) {
                return $shortLanguage;
            }
        }

        return $this->fallback();
    }

    private function requestLanguage(Request $request): ?string
    {
        $query = $request->query->get('language');

        if (is_scalar($query)) {
            return str_replace("\0", '', (string) $query);
        }

        $post = $request->request->get('language');

        return is_scalar($post) ? str_replace("\0", '', (string) $post) : null;
    }

    private function cookieLanguage(Request $request): ?string
    {
        $cookie = $request->cookies->get($this->cookieName);

        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        $content = $this->unsignedCookieContent($cookie);

        if ($content === null) {
            return null;
        }

        foreach (explode(':', $content['value']) as $entry) {
            [$name, $value] = array_pad(explode('=', $entry, 2), 2, null);

            if ($name !== 'language' || ! is_string($value)) {
                continue;
            }

            $decoded = base64_decode($value, true);

            if (! is_string($decoded)) {
                return null;
            }

            return $content['signed'] ? $this->legacyString($decoded) : $decoded;
        }

        return null;
    }

    /**
     * @return array{value: string, signed: bool}|null
     */
    private function unsignedCookieContent(string $cookie): ?array
    {
        if (strlen($cookie) >= 43 && substr($cookie, -43, 3) === ':_=') {
            $value = substr($cookie, 0, -43);
            $signature = substr($cookie, -40);

            if (hash_equals(sha1($value.$this->salt), $signature)) {
                return ['value' => $value, 'signed' => true];
            }

            return null;
        }

        if (str_contains($cookie, '=')) {
            return ['value' => $cookie, 'signed' => false];
        }

        return null;
    }

    private function legacyString(string $value): ?string
    {
        if (preg_match('/^s:(\d+):"(.*)";$/sD', $value, $matches) !== 1) {
            return null;
        }

        return strlen($matches[2]) === (int) $matches[1] ? $matches[2] : null;
    }

    private function isAvailable(string $language): bool
    {
        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D', $language) === 1
            && in_array($language, $this->availableLanguages, true);
    }

    private function fallback(): string
    {
        return $this->isAvailable($this->defaultLanguage) ? $this->defaultLanguage : 'en';
    }
}
