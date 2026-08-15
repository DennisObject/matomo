<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

use App\Matomo\CoreAdmin\Events\ConfigurationFileChanged;
use App\Matomo\CoreAdmin\Events\ConfigurationSaving;
use Illuminate\Contracts\Events\Dispatcher;
use Matomo\Ini\IniReader;
use Matomo\Ini\IniWriter;
use RuntimeException;

final readonly class IniTrustedHostConfiguration implements TrustedHostConfiguration
{
    private const string HEADER = "; <?php exit; ?> DO NOT REMOVE THIS LINE\n".
        '; file automatically generated or modified by Matomo; you can manually override '.
        "the default values in global.ini.php by redefining them in this file.\n";

    public function __construct(
        private string $path,
        private Dispatcher $events,
    ) {}

    public function replace(array $hosts): void
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException('The Matomo configuration file is not readable.');
        }

        $configuration = (new IniReader)->readFile($this->path);
        $general = $configuration['General'] ?? [];

        if (! is_array($general)) {
            throw new RuntimeException('The Matomo General configuration section is invalid.');
        }

        $general['trusted_hosts'] = $hosts;
        $configuration['General'] = $general;
        $event = new ConfigurationSaving($configuration);
        $this->events->dispatch($event);
        $content = (new IniWriter)->writeToString($event->configuration, self::HEADER);

        if (file_put_contents($this->path, $content, LOCK_EX) === false) {
            throw new RuntimeException('The Matomo configuration file is not writable.');
        }

        clearstatcache(true, $this->path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }

        if (file_get_contents($this->path) !== $content) {
            throw new RuntimeException('The Matomo configuration file did not write correctly.');
        }

        $this->events->dispatch(new ConfigurationFileChanged($this->path));
    }
}
