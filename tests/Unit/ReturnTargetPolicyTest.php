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
        self::assertSame(
            '/admin-dev/modules?return=%2Fadmin-dev%2Fsecurity&label=Security%20settings',
            $policy->fromReferer(
                'https://shop.example/admin-dev/modules?return=%2Fadmin-dev%2Fsecurity&label=Security%20settings',
                'shop.example',
                '/admin-dev'
            )
        );

        foreach ([
            'https://shop.example/admin-dev/\\attacker.example/steal',
            'https://shop.example/admin-dev/%5cattacker.example/steal',
            'https://shop.example/admin-dev/%5Cattacker.example/steal',
            'https://shop.example/admin-dev/%255cattacker.example/steal',
            "https://shop.example/admin-dev/modules\r\nX-Injected: true",
            "https://shop.example/admin-dev/modules\0suffix",
            'https://shop.example/admin-dev/modules?next=%0d%0aX-Injected%3A%20true',
            'https://shop.example/admin-dev/modules?next=%00',
            'https://shop.example/admin-dev/modules?next=%1f',
            'https://shop.example/admin-dev/modules?next=%7f',
            'https://shop.example//attacker.example/steal',
            'https://attacker.example/steal',
            'https://shop.example/front-office',
        ] as $unsafeReferer) {
            self::assertNull(
                $policy->fromReferer($unsafeReferer, 'shop.example', '/admin-dev'),
                $unsafeReferer
            );
        }
    }
}
