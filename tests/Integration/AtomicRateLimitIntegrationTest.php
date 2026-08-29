<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Module;
use Mpadmin2fa\Repository\SecurityRepository;
use PHPUnit\Framework\TestCase;

final class AtomicRateLimitIntegrationTest extends TestCase
{
    /** @var Connection */
    private $connection;

    /** @var string */
    private $scope;

    /** @var string */
    private $subjectHash;

    protected function setUp(): void
    {
        if ('1' !== getenv('MP2FA_INTEGRATION')) {
            self::markTestSkipped('Set MP2FA_INTEGRATION=1 inside an installed PS8 test shop.');
        }

        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
        $root = getenv('MP2FA_PS_ROOT') ?: dirname(__DIR__, 4);
        require_once $root . '/config/config.inc.php';
        Module::getInstanceByName('mpadmin2fa');

        $this->connection = DriverManager::getConnection([
            'dbname' => _DB_NAME_,
            'driver' => 'pdo_mysql',
            'host' => _DB_SERVER_,
            'password' => _DB_PASSWD_,
            'user' => _DB_USER_,
        ]);
        $this->scope = 'it_' . substr(hash('sha256', uniqid('', true)), 0, 20);
        $this->subjectHash = hash('sha256', uniqid('subject', true));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->scope, $this->subjectHash)) {
            $this->connection->delete(_DB_PREFIX_ . 'mp2fa_rate_limit', [
                'scope' => $this->scope,
                'subject_hash' => $this->subjectHash,
            ]);
        }
    }

    public function testSimultaneousFailuresAreAllCountedAndBlockMonotonically(): void
    {
        $workers = [];
        $worker = __DIR__ . '/rate_limit_worker.php';
        for ($index = 0; $index < 10; ++$index) {
            $process = proc_open(
                implode(' ', array_map('escapeshellarg', [
                    PHP_BINARY,
                    $worker,
                    $this->scope,
                    $this->subjectHash,
                ])),
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                ['MP2FA_PS_ROOT' => dirname(__DIR__, 4)]
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }

        $counts = [];
        foreach ($workers as [$process, $stdout, $stderr]) {
            $counts[] = (int) trim((string) stream_get_contents($stdout));
            $error = trim((string) stream_get_contents($stderr));
            fclose($stdout);
            fclose($stderr);
            self::assertSame(0, proc_close($process), $error);
        }
        sort($counts, SORT_NUMERIC);

        self::assertSame(range(1, 10), $counts);
        $row = $this->connection->fetchAssociative(
            'SELECT failures, blocked_until, last_failure_at FROM ' . _DB_PREFIX_ . 'mp2fa_rate_limit'
            . ' WHERE scope = ? AND subject_hash = ?',
            [$this->scope, $this->subjectHash]
        );
        self::assertIsArray($row);
        self::assertSame(10, (int) $row['failures']);
        self::assertNotNull($row['blocked_until']);
        self::assertNotNull($row['last_failure_at']);
    }

    public function testFailureCommittedAfterResetStartsAtOne(): void
    {
        $repository = new SecurityRepository($this->connection, _DB_PREFIX_);
        self::assertSame(1, $repository->incrementFailure($this->scope, $this->subjectHash, 5, 3600));
        $repository->clearFailures($this->scope, $this->subjectHash);
        self::assertSame(1, $repository->incrementFailure($this->scope, $this->subjectHash, 5, 3600));

        $row = $repository->rateLimit($this->scope, $this->subjectHash);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['failures']);
        self::assertNull($row['blocked_until']);
    }
}
