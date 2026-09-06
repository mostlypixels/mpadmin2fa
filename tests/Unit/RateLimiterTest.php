<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Repository\FailureCounterInterface;
use Mpadmin2fa\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testFailureUsesCountsReturnedByAtomicRepositoryOperation(): void
    {
        $counter = new InMemoryFailureCounter();
        $limiter = new RateLimiter($counter);

        self::assertSame(1, $limiter->failure('challenge', 42, '127.0.0.1'));
        self::assertSame(2, $limiter->failure('challenge', 42, '127.0.0.1'));
        self::assertSame([2, 2], array_values($counter->counts));
    }

    public function testUtcBlocksRemainEffectiveInTheShopTimezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Brussels');
            $repository = $this->createMock(FailureCounterInterface::class);
            $repository->method('rateLimit')->willReturn(['blocked_until' => gmdate('Y-m-d H:i:s', time() + 60)]);
            $this->expectException(\RuntimeException::class);
            (new RateLimiter($repository))->assertAllowed('challenge', 42, '127.0.0.1');
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testSuccessClearsEverySubject(): void
    {
        $counter = new InMemoryFailureCounter();
        $limiter = new RateLimiter($counter);
        $limiter->failure('challenge', 42, '127.0.0.1');

        $limiter->success('challenge', 42, '127.0.0.1');

        self::assertSame([], $counter->counts);
    }
}

final class InMemoryFailureCounter implements FailureCounterInterface
{
    /** @var array<string, int> */
    public $counts = [];

    public function incrementFailure(string $scope, string $subjectHash): int
    {
        $key = $scope . ':' . $subjectHash;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key];
    }

    public function rateLimit(string $scope, string $subjectHash): ?array
    {
        $key = $scope . ':' . $subjectHash;
        if (!isset($this->counts[$key])) {
            return null;
        }

        return [
            'blocked_until' => null,
            'failures' => $this->counts[$key],
        ];
    }

    public function clearFailures(string $scope, string $subjectHash): void
    {
        unset($this->counts[$scope . ':' . $subjectHash]);
    }
}
