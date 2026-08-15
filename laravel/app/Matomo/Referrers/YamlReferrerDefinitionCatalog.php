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

    /** @var list<string>|null */
    private ?array $socialOrder = null;

    public function __construct(
        private readonly string $socialsFile,
        private readonly string $aiAssistantsFile,
        private readonly string $socialIconsDirectory = '',
        private readonly string $aiAssistantIconsDirectory = '',
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

    public function socialUrl(string $name): ?string
    {
        return $this->firstDomain($name, $this->socials ??= $this->definitions($this->socialsFile));
    }

    public function aiAssistantUrl(string $name): ?string
    {
        return $this->firstDomain(
            $name,
            $this->aiAssistants ??= $this->definitions($this->aiAssistantsFile),
        );
    }

    public function socialNameAtPosition(int $position): ?string
    {
        $this->socials ??= $this->definitions($this->socialsFile);
        $this->socialOrder ??= array_values($this->socials);

        return $position < 1 ? null : ($this->socialOrder[$position - 1] ?? null);
    }

    public function socialLogo(string $name): string
    {
        return $this->logo($name, $this->socials ??= $this->definitions($this->socialsFile), 'socials');
    }

    public function aiAssistantLogo(string $name): string
    {
        return $this->logo(
            $name,
            $this->aiAssistants ??= $this->definitions($this->aiAssistantsFile),
            'aiAssistants',
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

    /** @param array<string, string> $definitions */
    private function firstDomain(string $name, array $definitions): ?string
    {
        foreach ($definitions as $domain => $definitionName) {
            if ($definitionName === $name) {
                return $domain;
            }
        }

        return null;
    }

    /** @param array<string, string> $definitions */
    private function logo(string $name, array $definitions, string $directory): string
    {
        $iconsDirectory = $directory === 'socials'
            ? $this->socialIconsDirectory
            : $this->aiAssistantIconsDirectory;

        foreach ($definitions as $domain => $definitionName) {
            if ($definitionName === $name && is_file($iconsDirectory.'/'.$domain.'.png')) {
                return 'plugins/Morpheus/icons/dist/'.$directory.'/'.$domain.'.png';
            }
        }

        return 'plugins/Morpheus/icons/dist/'.$directory.'/xx.png';
    }
}
