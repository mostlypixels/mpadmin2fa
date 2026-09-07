<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\FactorResetAuthorization;
use PHPUnit\Framework\TestCase;

final class FactorResetAuthorizationTest extends TestCase
{
    public function testResetRequiresSuperAdminNativeDeleteAndADifferentValidTarget(): void
    {
        $policy = new FactorResetAuthorization();
        foreach ([[0, 1, 42, true, 1], [7, 1, 42, false, 1], [7, 2, 42, true, 1], [7, 1, 7, true, 1], [7, 1, 0, true, 1]] as $inputs) {
            self::assertNotNull($policy->denialReason(...$inputs));
        }
        self::assertNull($policy->denialReason(7, 1, 42, true, 1));
        self::assertNull($policy->denialReason(7, 9, 42, true, 9));
        self::assertNotNull($policy->denialReason(7, 1, 42, true, 9));
    }
}
