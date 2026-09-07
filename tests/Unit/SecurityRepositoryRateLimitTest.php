<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

$moduleLoader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
class_exists(\PHPUnit\Framework\TestCase::class);
interface_exists(\PHPUnit\Framework\Test::class);
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
spl_autoload_register(static function ($class) use ($moduleLoader): void {
    if (0 === strpos($class, 'PHPUnit\\')) {
        $moduleLoader->loadClass($class);
    }
}, true, true);

use Doctrine\DBAL\Connection;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\RateLimiter;
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
                    return false !== strpos($sql, 'LAST_INSERT_ID(1)')
                        && false !== strpos($sql, 'LAST_INSERT_ID(failures + 1)')
                        && false !== strpos($sql, 'blocked_until IS NULL')
                        && false !== strpos($sql, 'ELSE blocked_until END')
                        && false !== strpos($sql, 'last_failure_at = VALUES(last_failure_at)');
                }),
                self::callback(static function (array $parameters): bool {
                    return 'challenge' === $parameters[0]
                        && str_repeat('a', 64) === $parameters[1]
                        && 1 === preg_match('/^\d{4}-\d{2}-\d{2} /', $parameters[2]);
                })
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
            3600
        ));
    }

    public function testUtcLockoutIsIndependentOfShopTimezoneAndSubject(): void
    {
        $previousTimezone = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Brussels', 'America/New_York', 'Asia/Kathmandu'] as $timezone) {
                date_default_timezone_set($timezone);
                foreach (['employee:42', 'ip:127.0.0.1'] as $subject) {
                    foreach ([-60, 60] as $offset) {
                        $connection = $this->createMock(Connection::class);
                        $connection->method('quoteIdentifier')->willReturnArgument(0);
                        $connection->method('fetchAssociative')->willReturnCallback(
                            static function (string $sql, array $parameters) use ($subject, $offset): array {
                                return ['blocked_until' => $parameters[1] === hash('sha256', $subject)
                                    ? gmdate('Y-m-d H:i:s', time() + $offset) : null];
                            }
                        );
                        $blocked = false;
                        try {
                            (new RateLimiter(new SecurityRepository($connection, 'ps_')))
                                ->assertAllowed('challenge', 42, '127.0.0.1');
                        } catch (\RuntimeException $exception) {
                            self::assertSame('Too many attempts. Try again later.', $exception->getMessage());
                            $blocked = true;
                        }
                        self::assertSame($offset > 0, $blocked, $timezone . ' / ' . $subject);
                    }
                }
            }
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }
}
