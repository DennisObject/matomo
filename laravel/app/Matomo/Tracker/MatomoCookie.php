<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Client\Browser;
use Illuminate\Http\Request;

final readonly class MatomoCookie
{
    public const int MAXIMUM_SIZE = 1024;

    public const string P3P_POLICY = "CP='OTI DSP COR NID STP UNI OTPa OUR'";

    private const string VALUE_SEPARATOR = ':';

    /** @param array<string, string> $values */
    public function __construct(private array $values) {}

    public static function fromRequest(Request $request, string $name): self
    {
        $value = $request->cookie($name);

        return self::fromRaw(is_string($value) ? $value : null);
    }

    public static function fromRaw(?string $raw): self
    {
        if (! is_string($raw) || $raw === '' || ! str_contains($raw, '=')) {
            return new self([]);
        }

        $values = [];
        foreach (explode(self::VALUE_SEPARATOR, $raw) as $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $name = substr($pair, 0, $separator);
            $value = substr($pair, $separator + 1);
            if (! is_numeric($value)) {
                $decoded = base64_decode($value, true);
                if ($decoded === false) {
                    return new self([]);
                }

                $value = $decoded;
            }

            $values[$name] = $value;
        }

        return new self($values);
    }

    /** @param array<int|string, float|int|string> $values */
    public static function encode(array $values): string
    {
        $pairs = [];
        foreach ($values as $name => $value) {
            $encoded = is_numeric($value) ? (string) $value : base64_encode((string) $value);
            $pairs[] = $name.'='.$encoded;
        }

        return implode(self::VALUE_SEPARATOR, $pairs);
    }

    public static function header(
        string $name,
        string $value,
        int $expiresAt,
        string $path,
        string $domain,
        bool $secure,
        string $sameSite,
    ): string {
        if ($domain !== '') {
            if (strncasecmp($domain, 'www.', 4) === 0) {
                $domain = substr($domain, 4);
            }

            $port = strpos($domain, ':');
            if ($port !== false) {
                $domain = substr($domain, 0, $port);
            }

            $domain = '.'.$domain;
        }

        $header = rawurlencode($name).'='.rawurlencode($value);
        if ($expiresAt !== 0) {
            $header .= '; expires='.(new DateTimeImmutable('@'.$expiresAt))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format(DateTimeInterface::COOKIE);
        }

        if ($path !== '') {
            $header .= '; path='.$path;
        }

        if ($domain !== '') {
            $header .= '; domain='.rawurlencode($domain);
        }

        if ($secure) {
            $header .= '; secure';
        }

        if ($sameSite !== '') {
            $header .= '; SameSite='.rawurlencode($sameSite);
        }

        return $header;
    }

    public static function sameSite(string $desired, bool $https, string $userAgent): string
    {
        $sameSite = ucfirst(strtolower($desired));
        if ($sameSite !== 'None') {
            return $sameSite;
        }

        if (! $https) {
            return 'Lax';
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        $shortName = $detector->getClient('short_name');

        return is_string($shortName) && $shortName !== ''
            && Browser::getBrowserFamily($shortName) === 'Safari'
            ? ''
            : $sameSite;
    }

    public function get(int|string $name): ?string
    {
        return $this->values[(string) $name] ?? null;
    }

    public function visitorId(): ?string
    {
        $visitorId = $this->get(0);

        return is_string($visitorId) && preg_match('/^[a-f0-9]{16}$/iD', $visitorId) === 1
            ? strtolower($visitorId)
            : null;
    }

    public function ignoresVisits(): bool
    {
        return $this->get('ignore') === '*';
    }
}
