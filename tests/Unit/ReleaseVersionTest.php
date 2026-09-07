<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2faBuild\ReleaseVersion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/ReleaseVersion.php';

final class ReleaseVersionTest extends TestCase
{
    public function testRcIdentityFitsNativeStorageAndRoundTrips(): void
    {
        self::assertSame('1.0.0rc1', ReleaseVersion::moduleVersionForTag('v1.0.0-rc.1'));
        self::assertSame('v1.0.0-rc.1', ReleaseVersion::tagForModuleVersion('1.0.0rc1'));
        self::assertTrue(version_compare('1.0.0rc1', '0.2.8', '>'));
        self::assertTrue(version_compare('1.0.0rc1', '1.0.0', '<'));
        self::assertSame('1.0.0', ReleaseVersion::moduleVersionForTag('v1.0.0'));
        self::assertStringContainsString(
            'upgrade_module_0_2_8($module)',
            (string) file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-1.0.0rc1.php')
        );
    }

    /** @dataProvider invalidTags */
    public function testRejectsWrongBranchAndUnstorableTags(string $tag): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseVersion::moduleVersionForTag($tag);
    }

    public function invalidTags(): array
    {
        return [['v2.0.0'], ['v3.0.0-rc.1'], ['v1.0.0-beta.1'], ['v1.0.0-rc.0'], ['v1.10.10-rc.1']];
    }
}
