<?php

declare(strict_types=1);

namespace App\Matomo\Referrers;

use Symfony\Component\Yaml\Yaml;

final class YamlReferrerDefinitionCatalog implements ReferrerDefinitionCatalog
{
    /** @var array<string, string>|null */
    private ?array $socials = null;

    /** @var array<string, string>|null */
    private ?array $aiAssistants = null;

    public function __construct(
        private readonly string $socialsFile,
        private readonly string $aiAssistantsFile,
    ) {}

    public function socialName(string $url): ?string
    {
        return $this->match($url, $this->socials ??= $this->definitions($this->socialsFile));
    }

    public function aiAssistantName(string $url): ?string
    {
        return $this->match(
            $url,
            $this->aiAssistants ??= $this->definitions($this->aiAssistantsFile),
        );
    }

    /** @return array<string, string> */
    private function definitions(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $parsed = Yaml::parseFile($file);

        if (! is_array($parsed)) {
            return [];
        }

        $definitions = [];

        foreach ($parsed as $name => $domains) {
            if (! is_string($name) || ! is_array($domains)) {
                continue;
            }

            foreach ($domains as $domain) {
                if (is_string($domain) && $domain !== '') {
                    $definitions[$domain] = $name;
                }
            }
        }

        return $definitions;
    }

    /** @param array<string, string> $definitions */
    private function match(string $url, array $definitions): ?string
    {
        foreach ($definitions as $domain => $name) {
            if (preg_match('#(^|[./])'.preg_quote($domain, '#').'([./]|$)#i', $url) === 1) {
                return $name;
            }
        }

        return null;
    }
}
