<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class RecoveryCodeService
{
    private const CODE_COUNT = 10;

    public function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::CODE_COUNT; ++$i) {
            $raw = strtoupper(bin2hex(random_bytes(10)));
            $codes[] = implode('-', str_split($raw, 5));
        }

        return $codes;
    }

    public function normalize(string $code): string
    {
        $compact = str_replace([' ', '-'], '', $code);
        if (1 !== preg_match('/^[A-Fa-f0-9]{20}$/D', $compact)) {
            return '';
        }

        return implode('-', str_split(strtoupper($compact), 5));
    }

    public function hashes(array $codes): array
    {
        return array_map(
            static function (string $code): string { return password_hash($code, PASSWORD_DEFAULT); },
            $codes
        );
    }
}
