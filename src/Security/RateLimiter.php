<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Mpadmin2fa\Repository\FailureCounterInterface;
use RuntimeException;

final class RateLimiter
{
    /** @var FailureCounterInterface */
    private $repository;

    public function __construct(FailureCounterInterface $repository)
    {
        $this->repository = $repository;
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
            $failures = $this->repository->incrementFailure($scope, $subject);
            $maximumFailures = max($maximumFailures, $failures);
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
