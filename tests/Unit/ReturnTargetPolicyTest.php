<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\ReturnTargetPolicy;
use PHPUnit\Framework\TestCase;

final class ReturnTargetPolicyTest extends TestCase
{
    public function testOnlyRelativeUrlsInsideTheCurrentBackOfficeAreAccepted(): void
    {
        $policy = new ReturnTargetPolicy();

        self::assertSame(
            '/admin-dev/modules?category=security',
            $policy->fromReferer(
                'https://shop.example/admin-dev/modules?category=security',
                'shop.example',
                '/admin-dev'
            )
        );
        self::assertNull($policy->fromReferer('https://attacker.example/steal', 'shop.example', '/admin-dev'));
        self::assertNull($policy->fromReferer('https://shop.example//attacker.example/steal', 'shop.example', '/admin-dev'));
        self::assertNull($policy->fromReferer('https://shop.example/front-office', 'shop.example', '/admin-dev'));
    }
}
