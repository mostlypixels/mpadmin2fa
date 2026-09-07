<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2faBuild\ReleaseVersion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/ReleaseVersion.php';

final class ReleaseVersionTest extends TestCase
{
    public function testRcIdentityRoundTrips(): void
    {
        self::assertSame('3.0.0rc1', ReleaseVersion::moduleVersionForTag('v3.0.0-rc.1'));
        self::assertSame('v3.0.0-rc.1', ReleaseVersion::tagForModuleVersion('3.0.0rc1'));
        self::assertTrue(version_compare('3.0.0rc1', '0.2.8', '>'));
        self::assertTrue(version_compare('3.0.0rc1', '3.0.0', '<'));
        self::assertSame('3.0.0', ReleaseVersion::moduleVersionForTag('v3.0.0'));
    }

    /** @dataProvider invalidTags */
    public function testRejectsTagsOwnedByOtherReleaseLines(string $tag): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseVersion::moduleVersionForTag($tag);
    }

    public static function invalidTags(): array
    {
        return [['v1.0.0'], ['v2.0.0-rc.1'], ['v3.0.0-beta.1'], ['v3.0.0-rc.0']];
    }
}
