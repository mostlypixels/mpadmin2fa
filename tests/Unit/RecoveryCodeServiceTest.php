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

        self::assertSame('ABCD-EFGH', $service->normalize('  abcd-efgh '));
    }
}
