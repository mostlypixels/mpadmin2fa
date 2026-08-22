<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Install\AdminTabHierarchy;
use PHPUnit\Framework\TestCase;

final class AdminTabHierarchyTest extends TestCase
{
    public function testUpgradeDefinitionsMatchPrestaShopNestedMenuStructure(): void
    {
        $definitions = [
            [
                'class_name' => 'AdminMpAdmin2fa',
                'route_name' => 'mpadmin2fa_settings',
                'icon' => 'security',
            ],
            [
                'class_name' => 'AdminMpAdmin2faAuthenticator',
                'route_name' => 'mpadmin2fa_authenticator',
                'parent_class_name' => 'AdminMpAdmin2fa',
            ],
            [
                'class_name' => 'AdminMpAdmin2faSecurity',
                'route_name' => 'mpadmin2fa_security_policy',
                'parent_class_name' => 'AdminMpAdmin2fa',
            ],
            [
                'class_name' => 'AdminMpAdmin2faSecurityActivity',
                'route_name' => 'mpadmin2fa_security_activity',
                'parent_class_name' => 'AdminMpAdmin2faSecurity',
            ],
        ];

        $upgradeDefinitions = (new AdminTabHierarchy())->buildUpgradeDefinitions($definitions);

        self::assertSame('AdminMpAdmin2fa_MTR', $upgradeDefinitions[0]['class_name']);
        self::assertSame('DEFAULT', $upgradeDefinitions[0]['parent_class_name']);
        self::assertSame('mpadmin2fa_settings', $upgradeDefinitions[0]['route_name']);
        self::assertSame('security', $upgradeDefinitions[0]['icon']);
        self::assertSame('AdminMpAdmin2fa_MTR', $upgradeDefinitions[1]['parent_class_name']);
        self::assertSame('AdminMpAdmin2fa_MTR', $upgradeDefinitions[2]['parent_class_name']);
        self::assertSame('AdminMpAdmin2faSecurity_MTR', $upgradeDefinitions[3]['class_name']);
        self::assertSame('AdminMpAdmin2fa_MTR', $upgradeDefinitions[3]['parent_class_name']);
        self::assertSame('AdminMpAdmin2faSecurity_MTR', $upgradeDefinitions[4]['parent_class_name']);
        self::assertSame('AdminMpAdmin2faSecurity_MTR', $upgradeDefinitions[5]['parent_class_name']);
    }
}
