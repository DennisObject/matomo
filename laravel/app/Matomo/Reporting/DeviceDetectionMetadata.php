<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Localization\MatomoTranslator;
use DeviceDetector\Parser\Client\Browser;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DeviceDetector\Parser\OperatingSystem;

final readonly class DeviceDetectionMetadata
{
    private const string OS_BOT = 'BOT';

    public function __construct(
        private MatomoTranslator $translator,
        private string $rootPath,
    ) {}

    /** @return array<int, string> */
    public function deviceTypeNames(): array
    {
        return AbstractDeviceParser::getAvailableDeviceTypeNames();
    }

    public function deviceTypeLabel(int|string $label, string $language): string
    {
        $translations = [
            'desktop' => 'General_Desktop',
            'smartphone' => 'DevicesDetection_Smartphone',
            'tablet' => 'DevicesDetection_Tablet',
            'phablet' => 'DevicesDetection_Phablet',
            'feature phone' => 'DevicesDetection_FeaturePhone',
            'console' => 'DevicesDetection_Console',
            'tv' => 'DevicesDetection_TV',
            'car browser' => 'DevicesDetection_CarBrowser',
            'smart display' => 'DevicesDetection_SmartDisplay',
            'camera' => 'DevicesDetection_Camera',
            'portable media player' => 'DevicesDetection_PortableMediaPlayer',
            'smart speaker' => 'DevicesDetection_SmartSpeaker',
            'wearable' => 'DevicesDetection_Wearable',
            'peripheral' => 'DevicesDetection_Peripheral',
        ];
        $types = AbstractDeviceParser::getAvailableDeviceTypes();
        $type = is_numeric($label) ? array_search((int) $label, $types, true) : $label;

        return is_string($type) && isset($translations[$type])
            ? $this->translator->translate($translations[$type], $language)
            : $this->unknown($language);
    }

    public function deviceTypeLogo(int|string $label): string
    {
        $type = is_numeric($label)
            ? array_search((int) $label, AbstractDeviceParser::getAvailableDeviceTypes(), true)
            : false;
        $name = is_string($type) ? str_replace(' ', '_', strtolower($type)) : 'unknown';

        return 'plugins/Morpheus/icons/dist/devices/'.$name.'.png';
    }

    public function brandLabel(string $label, string $language): string
    {
        return isset(AbstractDeviceParser::$deviceBrands[$label])
            ? ucfirst(AbstractDeviceParser::$deviceBrands[$label])
            : $this->unknown($language);
    }

    public function brandLogo(string $label): string
    {
        $label = preg_replace('/[^a-z0-9_-]+/i', '_', $label) ?? '';
        $path = 'plugins/Morpheus/icons/dist/brand/'.$label.'.png';

        return is_file($this->rootPath.'/'.$path)
            ? $path
            : 'plugins/Morpheus/icons/dist/brand/unk.png';
    }

    public function modelLabel(string $label, string $language): string
    {
        if (str_contains($label, ';')) {
            [$brand, $model] = explode(';', $label, 2);
        } else {
            $brand = '';
            $model = $label;
        }

        $brand = $brand !== '' ? $this->brandLabel($brand, $language) : '';

        if ($brand === $this->unknown($language)) {
            $brand = '';
        }

        if ($model === '') {
            $model = $this->unknown($language);
        } elseif (str_starts_with($model, 'generic ')) {
            $type = substr($model, 8);
            $device = $type === 'mobile'
                ? $this->translator->translate('DevicesDetection_MobileDevice', $language)
                : $this->deviceTypeLabel($type, $language);
            $model = $this->translator->translate('DevicesDetection_GenericDevice', $language, [$device]);
        }

        return $brand === '' ? $model : $brand.' - '.$model;
    }

    public function osFamilyLabel(string $label, string $language): string
    {
        if ($label === self::OS_BOT) {
            return 'Bot';
        }

        $family = OperatingSystem::getOsFamily($this->legacyOsCode($label));

        if ($family === 'Gaming Console') {
            return $this->translator->translate('DevicesDetection_Console', $language);
        }

        return is_string($family) && $family !== '' && $family !== 'unknown'
            ? $family
            : $this->unknown($language);
    }

    public function osFamilyLogo(string $label, string $language): string
    {
        $label = $this->legacyOsCode($label);
        $families = OperatingSystem::getAvailableOperatingSystemFamilies();

        return isset($families[$label][0])
            ? $this->osLogo((string) $families[$label][0], $language)
            : $this->osLogo($label, $language);
    }

    public function osLabel(string $label, string $language): string
    {
        if ($label === '') {
            return $this->unknown($language);
        }

        if (str_starts_with($label, self::OS_BOT)) {
            return 'Bot';
        }

        if ($label !== ';') {
            $name = OperatingSystem::getNameFromId(
                $this->legacyOsCode(substr($label, 0, 3)),
                substr($label, 4, 15),
            );

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $this->unknown($language);
    }

    public function osLogo(string $short, string $language): string
    {
        $path = 'plugins/Morpheus/icons/dist/os/%s.png';
        $short = $this->legacyOsCode($short);
        $systems = OperatingSystem::getAvailableOperatingSystems();

        if (strlen($short) > 3) {
            $code = array_search($short, $systems, true);
            $short = is_string($code) ? $code : substr($short, 0, 3);
        }

        if ($short !== '' && isset($systems[$short]) && is_file($this->rootPath.'/'.sprintf($path, $short))) {
            return sprintf($path, $short);
        }

        $family = $this->osFamilyLabel($short, $language);
        $families = OperatingSystem::getAvailableOperatingSystemFamilies();
        $familyCode = $families[$family][0] ?? null;

        return is_string($familyCode) && is_file($this->rootPath.'/'.sprintf($path, $familyCode))
            ? sprintf($path, $familyCode)
            : sprintf($path, 'UNK');
    }

    public function browserLabel(string $label, string $language): string
    {
        if ($label === '') {
            return $this->unknown($language);
        }

        $code = substr($label, 0, 2);
        $browsers = Browser::getAvailableBrowsers();

        if (isset($browsers[$code])) {
            return ucfirst($browsers[$code]);
        }

        return strlen($label) > 2 && ! str_contains($label, 'UNK')
            ? $label
            : $this->unknown($language);
    }

    public function browserVersionLabel(string $label, string $language): string
    {
        $position = strrpos($label, ';');

        if ($position === false) {
            return $this->unknown($language);
        }

        $short = substr($label, 0, $position);
        $version = substr($label, $position + 1);
        $browsers = Browser::getAvailableBrowsers();

        if ($short !== '' && isset($browsers[$short])) {
            return trim(ucfirst($browsers[$short]).' '.$version);
        }

        return strlen($short) > 2 && $short !== 'UNK'
            ? trim($short.' '.$version)
            : $this->unknown($language);
    }

    public function browserFamilyLogo(string $label, string $language): string
    {
        $browsers = Browser::getAvailableBrowsers();
        $families = Browser::getAvailableBrowserFamilies();

        if ($label !== '' && in_array($label, $browsers, true)) {
            return $this->browserLogo($label, $language);
        }

        return $label !== '' && isset($families[$label][0])
            ? $this->browserLogo((string) $families[$label][0], $language)
            : $this->browserLogo($label, $language);
    }

    public function browserLogo(string $short, string $language): string
    {
        $path = 'plugins/Morpheus/icons/dist/browsers/%s.png';
        $browsers = Browser::getAvailableBrowsers();

        if ($short !== '' && strlen($short) > 2) {
            $code = array_search($short, $browsers, true);
            $short = is_string($code) ? $code : substr($short, 0, 2);
        }

        if ($short !== '' && isset($browsers[$short]) && is_file($this->rootPath.'/'.sprintf($path, $short))) {
            return sprintf($path, $short);
        }

        $family = $this->browserFamilyName($short, $language);
        $families = Browser::getAvailableBrowserFamilies();

        foreach ($families[$family] ?? [] as $familyCode) {
            if (is_string($familyCode) && is_file($this->rootPath.'/'.sprintf($path, $familyCode))) {
                return sprintf($path, $familyCode);
            }
        }

        return sprintf($path, 'UNK');
    }

    public function validBrowserSegmentValue(string $label): string|false
    {
        return isset(Browser::getAvailableBrowsers()[$label]) || $label === 'UNK' ? $label : false;
    }

    public function browserEngineLabel(string $label, string $language): string
    {
        $label = [
            'ie' => 'Trident',
            'gecko' => 'Gecko',
            'khtml' => 'KHTML',
            'webkit' => 'WebKit',
            'opera' => 'Presto',
            'unknown' => '',
        ][$label] ?? $label;

        return [
            'Trident' => 'Trident (IE)',
            'Gecko' => 'Gecko (Firefox)',
            'KHTML' => 'KHTML (Konqueror)',
            'Presto' => 'Presto (Opera)',
            'WebKit' => 'WebKit (Safari)',
            'Blink' => 'Blink (Chrome, Opera)',
        ][$label] ?? ($label !== '' ? $label : $this->unknown($language));
    }

    public function translation(string $key, string $language): string
    {
        return $this->translator->translate($key, $language);
    }

    private function browserFamilyName(string $label, string $language): string
    {
        foreach (Browser::getAvailableBrowserFamilies() as $name => $family) {
            if (in_array($label, $family, true)) {
                return $name;
            }
        }

        return $this->unknown($language);
    }

    private function legacyOsCode(string $short): string
    {
        return [
            'IPA' => 'IOS', 'IPH' => 'IOS', 'IPD' => 'IOS', 'WIU' => 'WII',
            '3DS' => 'NDS', 'DSI' => 'NDS', 'PSV' => 'PSP', 'MAE' => 'SMG',
            'W10' => 'WIN', 'W2K' => 'WIN', 'W31' => 'WIN', 'WI7' => 'WIN',
            'WI8' => 'WIN', 'W81' => 'WIN', 'W95' => 'WIN', 'W98' => 'WIN',
            'WME' => 'WIN', 'WNT' => 'WIN', 'WS3' => 'WIN', 'WVI' => 'WIN',
            'WXP' => 'WIN',
        ][$short] ?? $short;
    }

    private function unknown(string $language): string
    {
        return $this->translator->translate('General_Unknown', $language);
    }
}
