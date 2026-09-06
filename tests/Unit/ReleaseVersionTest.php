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
        self::assertSame('2.0.0rc1', ReleaseVersion::moduleVersionForTag('v2.0.0-rc.1'));
        self::assertSame('v2.0.0-rc.1', ReleaseVersion::tagForModuleVersion('2.0.0rc1'));
        self::assertTrue(version_compare('2.0.0rc1', '0.2.8', '>'));
        self::assertTrue(version_compare('2.0.0rc1', '2.0.0', '<'));
        self::assertSame('2.0.0', ReleaseVersion::moduleVersionForTag('v2.0.0'));
        self::assertSame('v2.0.0', ReleaseVersion::tagForModuleVersion('2.0.0'));
    }

    /** @dataProvider invalidTags */
    public function testRejectsWrongBranchAndUnstorableTags(string $tag): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseVersion::moduleVersionForTag($tag);
    }

    public function invalidTags(): array
    {
        return [['v1.0.0'], ['v3.0.0-rc.1'], ['v2.0.0-beta.1'], ['v2.0.0-rc.0'], ['v2.10.10-rc.1']];
    }
}
