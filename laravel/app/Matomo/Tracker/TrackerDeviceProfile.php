<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackerDeviceProfile
{
    public function __construct(
        public string $browserName = 'UNK',
        public string $browserVersion = '',
        public string $operatingSystem = 'UNK',
        public ?string $operatingSystemVersion = null,
        public ?int $deviceType = null,
        public ?string $deviceBrand = null,
        public ?string $deviceModel = null,
        public string $browserEngine = '',
        public bool $isBot = false,
        public bool $flash = false,
        public bool $java = false,
        public bool $quickTime = false,
        public bool $realPlayer = false,
        public bool $pdf = false,
        public bool $windowsMedia = false,
        public bool $silverlight = false,
        public string $configId = '',
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function visitColumns(): array
    {
        return [
            'config_browser_name' => $this->browserName,
            'config_browser_version' => $this->browserVersion,
            'config_os' => $this->operatingSystem,
            'config_os_version' => $this->operatingSystemVersion,
            'config_device_type' => $this->deviceType,
            'config_device_brand' => $this->deviceBrand,
            'config_device_model' => $this->deviceModel,
            'config_flash' => $this->flash ? 1 : 0,
            'config_java' => $this->java ? 1 : 0,
            'config_quicktime' => $this->quickTime ? 1 : 0,
            'config_realplayer' => $this->realPlayer ? 1 : 0,
            'config_pdf' => $this->pdf ? 1 : 0,
            'config_windowsmedia' => $this->windowsMedia ? 1 : 0,
            'config_silverlight' => $this->silverlight ? 1 : 0,
        ];
    }
}
