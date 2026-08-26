<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Security\ProfilePolicyCleaner;
use PHPUnit\Framework\TestCase;

final class ProfilePolicyCleanerTest extends TestCase
{
    public static function profileLists(): iterable
    {
        yield 'middle ID' => ['2,3,4', 3, '2,4'];
        yield 'only ID' => ['3', 3, ''];
        yield 'unrelated ID' => ['2,4', 3, '2,4'];
        yield 'invalid and duplicate values' => ['0,2,2,garbage,4', 3, '2,4'];
    }

    /** @dataProvider profileLists */
    public function testDeletedProfileIsRemoved(string $storedIds, int $deletedId, string $expected): void
    {
        self::assertSame($expected, ProfilePolicyCleaner::removeFromList($storedIds, $deletedId));
    }
}
