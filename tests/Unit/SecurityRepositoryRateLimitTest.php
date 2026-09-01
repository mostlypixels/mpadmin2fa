<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

$moduleLoader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
class_exists(\PHPUnit\Framework\TestCase::class);
interface_exists(\PHPUnit\Framework\Test::class);
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($moduleLoader): void {
    if (str_starts_with($class, 'PHPUnit\\')) {
        $moduleLoader->loadClass($class);
    }
}, true, true);

use Doctrine\DBAL\Connection;
use Mpadmin2fa\Repository\SecurityRepository;
use PHPUnit\Framework\TestCase;

final class SecurityRepositoryRateLimitTest extends TestCase
{
    public function testIncrementIsAtomicAndReturnsTheDatabaseCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'LAST_INSERT_ID(1)')
                        && str_contains($sql, 'LAST_INSERT_ID(failures + 1)')
                        && str_contains($sql, 'blocked_until IS NULL')
                        && str_contains($sql, 'ELSE blocked_until END')
                        && str_contains($sql, 'last_failure_at = VALUES(last_failure_at)');
                }),
                self::callback(static function (array $parameters): bool {
                    return 'challenge' === $parameters[0]
                        && str_repeat('a', 64) === $parameters[1]
                        && 1 === preg_match('/^\d{4}-\d{2}-\d{2} /', $parameters[2]);
                }),
            )
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT LAST_INSERT_ID()')
            ->willReturn('7');

        $repository = new SecurityRepository($connection, 'ps_');

        self::assertSame(7, $repository->incrementFailure(
            'challenge',
            str_repeat('a', 64),
            5,
            3600,
        ));
    }
}
