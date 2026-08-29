<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AtomicFailureRepositoryTest extends TestCase
{
    public function testFailureIncrementIsAtomicAndUpdatesItsTimestamp(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2) . '/src/Repository/SecurityRepository.php');

        self::assertIsString($repository);
        self::assertStringContainsString('INSERT IGNORE INTO', $repository);
        self::assertStringContainsString('failures = LAST_INSERT_ID(failures + 1)', $repository);
        self::assertStringContainsString('last_failure_at = ?, date_upd = ?', $repository);
        self::assertStringContainsString('SELECT LAST_INSERT_ID()', $repository);
        self::assertStringNotContainsString('function recordFailure(', $repository);
    }

    public function testSchemaAndUpgradeContainLastFailureTimestamp(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/src/Install/SchemaInstaller.php');
        $module = file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');

        self::assertIsString($schema);
        self::assertIsString($module);
        self::assertStringContainsString('last_failure_at DATETIME NULL', $schema);
        self::assertStringContainsString('ensureRateLimitLastFailureAt', $module);
    }
}
