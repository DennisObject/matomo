<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

use App\Matomo\CoreAdmin\Events\CustomLogoChanged;
use App\Matomo\Options\MutableOptionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;

final readonly class FileBrandingManager implements BrandingManager
{
    private const string BRANDING_OPTION = 'branding_use_custom_logo';

    private const string LOGO = 'logo.png';

    private const string HEADER_LOGO = 'logo-header.png';

    private const string FAVICON = 'favicon.png';

    public function __construct(
        private MutableOptionRepository $options,
        private Filesystem $files,
        private Dispatcher $events,
        private string $publicDirectory,
        private string $publicRelativeDirectory,
        private string $temporaryLogosDirectory,
    ) {}

    public function update(
        string $login,
        bool $useCustomLogo,
        bool $hasCustomLogo,
        bool $hasCustomFavicon,
    ): array {
        $temporaryDirectory = $this->temporaryDirectory($login);

        if (! $useCustomLogo || (! $hasCustomLogo && ! $hasCustomFavicon)) {
            $this->removeLogos($temporaryDirectory);
            $this->options->set(self::BRANDING_OPTION, '0', true);

            return ['useCustomLogo' => false];
        }

        $this->options->set(self::BRANDING_OPTION, '1', true);
        $response = ['useCustomLogo' => true];

        if ($hasCustomLogo && $this->hasFiles($temporaryDirectory, [self::LOGO, self::HEADER_LOGO])) {
            $this->publish($temporaryDirectory, [self::LOGO, self::HEADER_LOGO]);
            $response['customLogoPath'] = $this->relativePath(self::LOGO);
        }

        if ($hasCustomFavicon && $this->hasFiles($temporaryDirectory, [self::FAVICON])) {
            $this->publish($temporaryDirectory, [self::FAVICON]);
            $response['customFaviconPath'] = $this->relativePath(self::FAVICON);
        }

        return $response;
    }

    /** @param list<string> $names */
    private function hasFiles(string $temporaryDirectory, array $names): bool
    {
        foreach ($names as $name) {
            if (! $this->files->isFile($temporaryDirectory.'/'.$name)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $names */
    private function publish(string $temporaryDirectory, array $names): void
    {
        $this->files->ensureDirectoryExists($this->publicDirectory);

        foreach ($names as $name) {
            $source = $temporaryDirectory.'/'.$name;
            $destination = $this->publicDirectory.'/'.$name;

            if (! $this->files->copy($source, $destination)) {
                return;
            }

            $this->events->dispatch(new CustomLogoChanged($destination));
            $this->files->delete($source);
        }
    }

    private function removeLogos(string $temporaryDirectory): void
    {
        $this->files->delete([
            $this->publicDirectory.'/'.self::LOGO,
            $this->publicDirectory.'/'.self::HEADER_LOGO,
            $this->publicDirectory.'/'.self::FAVICON,
        ]);

        if ($this->files->isDirectory($temporaryDirectory)) {
            $this->files->deleteDirectory($temporaryDirectory);
        }
    }

    private function temporaryDirectory(string $login): string
    {
        return rtrim($this->temporaryLogosDirectory, '/').'/'.sha1($login);
    }

    private function relativePath(string $name): string
    {
        return trim($this->publicRelativeDirectory, '/').'/'.$name;
    }
}
