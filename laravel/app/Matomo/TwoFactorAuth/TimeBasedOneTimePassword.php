<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

final readonly class TimeBasedOneTimePassword
{
    public function matches(
        #[\SensitiveParameter]
        string $secret,
        #[\SensitiveParameter]
        string $code,
        int $window = 2,
        ?int $now = null,
    ): bool {
        if (strlen($code) !== 6 || preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }

        $now ??= time();
        $slice = intdiv($now, 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->codeAt($secret, $slice + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function codeAt(#[\SensitiveParameter] string $secret, int $timeSlice): string
    {
        $key = $this->decodeBase32($secret);
        if ($key === '') {
            return '000000';
        }

        $time = "\0\0\0\0".pack('N', $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4));
        $number = ($value[1] ?? 0) & 0x7FFFFFFF;

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(#[\SensitiveParameter] string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(str_replace(['=', ' ', '-'], '', $secret));
        $buffer = 0;
        $bits = 0;
        $output = '';

        foreach (str_split($secret) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) {
                return '';
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }
}
