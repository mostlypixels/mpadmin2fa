<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Db;
use Mpadmin2fa\Repository\SecurityRepository;
use RuntimeException;

final class RateLimiter
{
    private const FREE_FAILURES = 5;
    private const MAX_DELAY_SECONDS = 3600;

    /** @var SecurityRepository */
    private $repository;

    public function __construct(SecurityRepository $repository)
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
            $this->recordFailureAtomically($scope, $subject);
            $row = $this->repository->rateLimit($scope, $subject);
            $maximumFailures = max($maximumFailures, (int) ($row['failures'] ?? 0));
        }

        return $maximumFailures;
    }

    public function success(string $scope, int $employeeId, ?string $ip): void
    {
        foreach ($this->subjects($employeeId, $ip) as $subject) {
            $this->repository->clearFailures($scope, $subject);
        }
    }

    private function recordFailureAtomically(string $scope, string $subject): void
    {
        $table = '`' . _DB_PREFIX_ . 'mp2fa_rate_limit`';
        $now = pSQL(gmdate('Y-m-d H:i:s'));
        $sql = 'INSERT INTO ' . $table
            . ' (scope, subject_hash, failures, blocked_until, date_upd) VALUES ('
            . '"' . pSQL($scope) . '", "' . pSQL($subject) . '", 1, NULL, "' . $now . '")'
            . ' ON DUPLICATE KEY UPDATE'
            . ' blocked_until = CASE WHEN failures + 1 >= ' . self::FREE_FAILURES
            . ' THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST('
            . self::MAX_DELAY_SECONDS . ', 60 * POW(2, failures + 1 - ' . self::FREE_FAILURES . ')) SECOND)'
            . ' ELSE NULL END,'
            . ' failures = failures + 1,'
            . ' date_upd = "' . $now . '"';

        if (!Db::getInstance()->execute($sql)) {
            throw new RuntimeException('Unable to update the two-factor authentication rate limit.');
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
