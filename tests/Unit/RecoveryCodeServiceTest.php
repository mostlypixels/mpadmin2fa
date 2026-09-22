<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\RecoveryCodeService;
use PHPUnit\Framework\TestCase;

final class RecoveryCodeServiceTest extends TestCase
{
    public function testCodesAreUniqueRandomAndOneWayHashed(): void
    {
        $service = new RecoveryCodeService();
        $codes = $service->generate();
        $hashes = $service->hashes($codes);

        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        self::assertCount(10, $hashes);
        foreach ($codes as $index => $code) {
            self::assertMatchesRegularExpression('/^[A-F0-9]{5}(?:-[A-F0-9]{5}){3}$/', $code);
            self::assertNotSame($code, $hashes[$index]);
            self::assertTrue(password_verify($code, $hashes[$index]));
        }
    }

    public function testNormalizationIsPredictable(): void
    {
        $service = new RecoveryCodeService();
        $canonical = 'A1B2C-D3E4F-56789-ABCDE';
        $hash = password_hash($canonical, PASSWORD_DEFAULT);

        foreach ([
            'A1B2C-D3E4F-56789-ABCDE',
            'a1b2c-d3e4f-56789-abcde',
            'A1B2CD3E4F56789ABCDE',
            'A1B2C D3E4F 56789 ABCDE',
            '  a1b2c - D3e4f 56789-abcde  ',
        ] as $variant) {
            $normalized = $service->normalize($variant);

            self::assertSame($canonical, $normalized);
            self::assertTrue(password_verify($normalized, $hash));
        }
    }

    public function testNormalizationRejectsAnythingExceptTwentyHexadecimalCharactersAndKnownSeparators(): void
    {
        $service = new RecoveryCodeService();
        $hash = password_hash('A1B2C-D3E4F-56789-ABCDE', PASSWORD_DEFAULT);

        foreach ([
            'A1B2C-D3E4F-56789-ABCD',
            'A1B2C-D3E4F-56789-ABCDE0',
            'A1B2C-D3E4F-56789-ABCDG',
            'A1B2C.D3E4F.56789.ABCDE',
            "A1B2C\tD3E4F 56789 ABCDE",
            "A1B2C-D3E4F-56789-ABCDE\n",
            "A1B2C-D3E4F-56789-ABCDE\0",
        ] as $invalid) {
            self::assertSame('', $service->normalize($invalid));
            self::assertFalse(password_verify($service->normalize($invalid), $hash));
        }

        self::assertFalse(password_verify(
            $service->normalize('A1B2C-D3E4F-56789-ABCDF'),
            $hash
        ));
    }
}
