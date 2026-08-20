<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;

final class TrackerDeviceDetector
{
    /**
     * @param  array<string, mixed>  $clientHints
     */
    public function detect(
        string $userAgent,
        array $clientHints,
        Request $request,
        int $siteId,
        string $ipAddress,
        string $browserLanguage,
        string $salt,
    ): TrackerDeviceProfile {
        $detector = new DeviceDetector($userAgent, ClientHints::factory($clientHints));
        $detector->discardBotInformation();
        $detector->parse();

        $client = $detector->getClient();
        $client = is_array($client) ? $client : [];

        $browser = ($client['type'] ?? null) === 'browser' ? $client : [];
        $os = $detector->getOs();
        $os = is_array($os) ? $os : [];

        $isBot = $detector->isBot();
        $browserName = is_string($browser['short_name'] ?? null) && $browser['short_name'] !== ''
            ? $browser['short_name']
            : 'UNK';
        $browserVersion = is_string($browser['version'] ?? null) ? $browser['version'] : '';
        $operatingSystem = $isBot
            ? 'BOT'
            : (is_string($os['short_name'] ?? null) && $os['short_name'] !== '' ? $os['short_name'] : 'UNK');
        $osVersion = is_string($os['version'] ?? null) && $os['version'] !== '' ? mb_substr($os['version'], 0, 100) : null;
        $brand = $detector->getBrandName();
        $model = $detector->getModel();
        $deviceType = $detector->getDevice();
        $engine = $browser['engine'] ?? '';
        $plugins = $this->plugins($request);
        if (($client['name'] ?? null) === 'Internet Explorer') {
            $plugins['cookie'] = false;
        }

        $fingerprint = $operatingSystem
            .$browserName.$browserVersion
            .(int) $plugins['flash'].(int) $plugins['java'].'0'
            .(int) $plugins['quicktime'].(int) $plugins['realplayer'].(int) $plugins['pdf']
            .(int) $plugins['windowsmedia'].'0'.(int) $plugins['silverlight'].(int) $plugins['cookie']
            .$ipAddress
            .mb_substr($browserLanguage, 0, 20)
            .$salt
            .$siteId;

        return new TrackerDeviceProfile(
            browserName: mb_substr($browserName, 0, 10),
            browserVersion: mb_substr($browserVersion, 0, 20),
            operatingSystem: mb_substr($operatingSystem, 0, 3),
            operatingSystemVersion: $osVersion,
            deviceType: is_int($deviceType) ? $deviceType : null,
            deviceBrand: $brand === '' ? null : mb_substr($brand, 0, 100),
            deviceModel: $model === '' ? null : mb_substr($model, 0, 100),
            browserEngine: is_string($engine) ? mb_substr($engine, 0, 40) : '',
            isBot: $isBot,
            flash: $plugins['flash'],
            java: $plugins['java'],
            quickTime: $plugins['quicktime'],
            realPlayer: $plugins['realplayer'],
            pdf: $plugins['pdf'],
            windowsMedia: $plugins['windowsmedia'],
            silverlight: $plugins['silverlight'],
            configId: substr(md5($fingerprint, true), 0, 8),
        );
    }

    /** @return array{flash: bool, java: bool, quicktime: bool, realplayer: bool, pdf: bool, windowsmedia: bool, silverlight: bool, cookie: bool} */
    private function plugins(Request $request): array
    {
        $flags = [];
        foreach (['fla' => 'flash', 'java' => 'java', 'qt' => 'quicktime', 'realp' => 'realplayer', 'pdf' => 'pdf', 'wma' => 'windowsmedia', 'ag' => 'silverlight', 'cookie' => 'cookie'] as $parameter => $name) {
            $flags[$name] = in_array($request->input($parameter), [1, '1', true], true);
        }

        return $flags;
    }
}
