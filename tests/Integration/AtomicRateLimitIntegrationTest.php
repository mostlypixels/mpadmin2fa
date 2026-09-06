<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mpadmin2fa\Repository\SecurityRepository;
use PHPUnit\Framework\TestCase;

final class AtomicRateLimitIntegrationTest extends TestCase
{
    /** @var Connection */
    private $connection;
    /** @var SecurityRepository */
    private $repository;
    /** @var string */
    private $scope;
    /** @var string */
    private $subjectHash;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'dbname' => _DB_NAME_, 'driver' => 'pdo_mysql', 'host' => _DB_SERVER_,
            'password' => _DB_PASSWD_, 'user' => _DB_USER_,
        ]);
        $this->repository = new SecurityRepository($this->connection, _DB_PREFIX_);
        $this->scope = 'it_' . bin2hex(random_bytes(10));
        $this->subjectHash = hash('sha256', random_bytes(32));
    }

    protected function tearDown(): void
    {
        $this->connection->delete(_DB_PREFIX_ . 'mp2fa_rate_limit', ['scope' => $this->scope]);
        $this->connection->close();
    }

    public function initialCounts(): array
    {
        return ['simultaneous first failures' => [0], 'existing row' => [3]];
    }

    /** @dataProvider initialCounts */
    public function testSimultaneousFailures(int $initial): void
    {
        for ($index = 0; $index < $initial; ++$index) {
            $this->repository->incrementFailure($this->scope, $this->subjectHash);
        }
        $workers = [];
        try {
            for ($index = 0; $index < 10; ++$index) {
                $process = proc_open(
                    implode(' ', array_map('escapeshellarg', [
                        PHP_BINARY, __DIR__ . '/rate_limit_worker.php', $this->scope, $this->subjectHash,
                    ])),
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 20);
                $workers[] = [$process, $pipes];
            }
            // Every worker has its own database connection before any increment starts.
            foreach ($workers as $worker) {
                $ready = fgets($worker[1][1]);
                if ("READY\n" !== $ready) {
                    stream_set_blocking($worker[1][2], false);
                    self::fail('Worker failed before the barrier: ' . stream_get_contents($worker[1][2]));
                }
                self::assertSame("READY\n", $ready);
            }
            foreach ($workers as $worker) {
                fwrite($worker[1][0], "GO\n");
                fclose($worker[1][0]);
            }
            $counts = [];
            foreach ($workers as $worker) {
                $counts[] = (int) trim(stream_get_contents($worker[1][1]));
                $error = stream_get_contents($worker[1][2]);
                self::assertSame(0, proc_close($worker[0]), $error);
            }
            sort($counts, SORT_NUMERIC);
            self::assertSame(range($initial + 1, $initial + 10), $counts);
            $this->assertStoredDelay($initial + 10);
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker[0])) {
                    proc_terminate($worker[0]);
                    proc_close($worker[0]);
                }
                foreach ($worker[1] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        }
    }

    public function testSequentialThresholdsAndResetOrdering(): void
    {
        for ($count = 1; $count <= 13; ++$count) {
            self::assertSame($count, $this->repository->incrementFailure($this->scope, $this->subjectHash));
            $this->assertStoredDelay($count);
        }
        $other = hash('sha256', 'other subject');
        self::assertSame(1, $this->repository->incrementFailure($this->scope, $other));
        // Reset linearizes at DELETE: a later failure starts at one, a prior failure is removed.
        $this->repository->clearFailures($this->scope, $this->subjectHash);
        $this->repository->clearFailures($this->scope, $this->subjectHash);
        self::assertNull($this->repository->rateLimit($this->scope, $this->subjectHash));
        self::assertSame(1, $this->repository->incrementFailure($this->scope, $this->subjectHash));
        $this->assertStoredDelay(1);
        self::assertSame(1, (int) $this->repository->rateLimit($this->scope, $other)['failures']);
    }

    private function assertStoredDelay(int $count): void
    {
        $row = $this->repository->rateLimit($this->scope, $this->subjectHash);
        self::assertIsArray($row);
        self::assertSame($count, (int) $row['failures']);
        self::assertNotNull($row['last_failure_at']);
        if ($count < 5) {
            self::assertNull($row['blocked_until']);
        } else {
            self::assertSame(
                (int) min(3600, 60 * pow(2, $count - 5)),
                strtotime($row['blocked_until'] . ' UTC') - strtotime($row['last_failure_at'] . ' UTC')
            );
            self::assertGreaterThan(time(), strtotime($row['blocked_until'] . ' UTC'));
        }
    }
}
