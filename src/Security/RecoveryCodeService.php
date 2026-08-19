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
        return strtoupper(trim($code));
    }

    public function hashes(array $codes): array
    {
        return array_map(
            static fn (string $code): string => password_hash($code, PASSWORD_DEFAULT),
            $codes
        );
    }
}
