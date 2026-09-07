<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class UtcRateLimitIntegrationTest extends TestCase
{
    public function testDatabaseUtcLockoutsIgnorePhpAndSqlSessionTimezones(): void
    {
        if ('1' !== getenv('MP2FA_UTC_INTEGRATION') && '1' !== getenv('MP2FA_INTEGRATION')) {
            self::markTestSkipped('Set MP2FA_UTC_INTEGRATION=1 with a disposable MySQL database.');
        }
        require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
        if ('1' === getenv('MP2FA_INTEGRATION')) {
            $root = getenv('MP2FA_PS_ROOT') ?: dirname(__DIR__, 4);
            require_once $root . '/config/config.inc.php';
        }
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => getenv('MP2FA_UTC_DB_HOST') ?: _DB_SERVER_,
            'dbname' => '1' === getenv('MP2FA_UTC_INTEGRATION') ? 'mp2fa_utc' : _DB_NAME_,
            'user' => '1' === getenv('MP2FA_UTC_INTEGRATION') ? 'root' : _DB_USER_,
            'password' => getenv('MP2FA_UTC_DB_PASSWORD') ?: _DB_PASSWD_,
        ]);
        $prefix = 'utc_' . bin2hex(random_bytes(6)) . '_';
        $table = $prefix . 'mp2fa_rate_limit';
        $previousTimezone = date_default_timezone_get();
        try {
            $connection->executeStatement('CREATE TABLE ' . $table . ' ('
                . 'scope VARCHAR(64) NOT NULL, subject_hash CHAR(64) NOT NULL,'
                . 'failures INT NOT NULL, blocked_until DATETIME NULL, last_failure_at DATETIME NOT NULL,'
                . 'PRIMARY KEY (scope, subject_hash))');
            $repository = new SecurityRepository($connection, $prefix);
            $limiter = new RateLimiter($repository);
            foreach (['+02:00', '-05:00'] as $sqlTimezone) {
                $connection->executeStatement('SET time_zone = ?', [$sqlTimezone]);
                foreach (['UTC', 'Europe/Brussels', 'America/New_York', 'Asia/Kathmandu'] as $phpTimezone) {
                    date_default_timezone_set($phpTimezone);
                    $limiter->success('challenge', 42, '127.0.0.1');
                    for ($attempt = 1; $attempt <= 5; ++$attempt) {
                        self::assertSame($attempt, $limiter->failure('challenge', 42, '127.0.0.1'));
                    }
                    // Each subject must independently block the request.
                    foreach ([[42, '127.0.0.2'], [43, '127.0.0.1']] as $identity) {
                        $blocked = false;
                        try {
                            $limiter->assertAllowed('challenge', $identity[0], $identity[1]);
                        } catch (\RuntimeException $exception) {
                            self::assertSame('Too many attempts. Try again later.', $exception->getMessage());
                            $blocked = true;
                        }
                        self::assertTrue($blocked, $phpTimezone . ' / ' . $sqlTimezone);
                    }
                    $remaining = (int) $connection->fetchOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), blocked_until) FROM ' . $table . ' LIMIT 1');
                    self::assertGreaterThanOrEqual(55, $remaining);
                    self::assertLessThanOrEqual(60, $remaining);
                    $connection->executeStatement('UPDATE ' . $table . ' SET blocked_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)');
                    $limiter->assertAllowed('challenge', 42, '127.0.0.1');
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            date_default_timezone_set($previousTimezone);
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
            $connection->close();
        }
    }
}
