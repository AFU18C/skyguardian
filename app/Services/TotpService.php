<?php

namespace App\Services;

use RuntimeException;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max(16, min(64, $bytes))));
    }

    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -max(0, $window); $offset <= max(0, $window); $offset++) {
            if (hash_equals($this->at($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $secret, string $email, string $issuer = 'SkyGuardian'): string
    {
        $label = rawurlencode($issuer.':'.$email);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<int, string> */
    public function recoveryCodes(int $count = 8): array
    {
        return collect(range(1, max(5, min(12, $count))))
            ->map(function (): string {
                $value = mb_strtoupper(bin2hex(random_bytes(10)));

                return implode('-', str_split($value, 5));
            })
            ->all();
    }

    private function at(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $result = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer = $bits > 0 ? $buffer & ((1 << $bits) - 1) : 0;
        }
        if ($bits > 0) {
            $result .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $result;
    }

    private function base32Decode(string $value): string
    {
        $value = mb_strtoupper(preg_replace('/[^A-Z2-7]/i', '', $value) ?? '');
        $buffer = 0;
        $bits = 0;
        $result = '';
        foreach (str_split($value) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('Некорректный секрет двухфакторной аутентификации.');
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $result .= chr(($buffer >> $bits) & 0xff);
                $buffer = $bits > 0 ? $buffer & ((1 << $bits) - 1) : 0;
            }
        }

        return $result;
    }
}
