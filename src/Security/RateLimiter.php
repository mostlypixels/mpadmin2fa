<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Mpadmin2fa\Repository\SecurityRepository;
use RuntimeException;

final class RateLimiter
{
    private const FREE_FAILURES = 5;
    private const MAX_DELAY_SECONDS = 3600;

    public function __construct(private readonly SecurityRepository $repository)
    {
    }

    public function assertAllowed(string $scope, int $employeeId, ?string $ip): void
    {
        foreach ($this->subjects($employeeId, $ip) as $subject) {
            $row = $this->repository->rateLimit($scope, $subject);
            if ($row && $row['blocked_until'] && strtotime((string) $row['blocked_until']) > time()) {
                throw new RuntimeException('Too many attempts. Try again later.');
            }
        }
    }

    public function failure(string $scope, int $employeeId, ?string $ip): int
    {
        $maximumFailures = 0;
        foreach ($this->subjects($employeeId, $ip) as $subject) {
            $row = $this->repository->rateLimit($scope, $subject);
            $failures = ((int) ($row['failures'] ?? 0)) + 1;
            $maximumFailures = max($maximumFailures, $failures);
            $blockedUntil = null;
            if ($failures >= self::FREE_FAILURES) {
                $delay = min(self::MAX_DELAY_SECONDS, 60 * (2 ** ($failures - self::FREE_FAILURES)));
                $blockedUntil = gmdate('Y-m-d H:i:s', time() + $delay);
            }
            $this->repository->recordFailure($scope, $subject, $failures, $blockedUntil);
        }

        return $maximumFailures;
    }

    public function success(string $scope, int $employeeId, ?string $ip): void
    {
        foreach ($this->subjects($employeeId, $ip) as $subject) {
            $this->repository->clearFailures($scope, $subject);
        }
    }

    private function subjects(int $employeeId, ?string $ip): array
    {
        return [
            hash('sha256', 'employee:' . $employeeId),
            hash('sha256', 'ip:' . ($ip ?? 'unknown')),
        ];
    }
}
