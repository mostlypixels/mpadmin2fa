<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Doctrine\DBAL\Connection;

final class RateLimitStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function find(string $scope, string $subjectHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $this->table() . ' WHERE scope = ? AND subject_hash = ?',
            [$scope, $subjectHash]
        );

        return $row ?: null;
    }

    public function increment(string $scope, string $subjectHash, int $freeFailures, int $maxDelaySeconds): int
    {
        $sql = 'INSERT INTO ' . $this->table()
            . ' (scope, subject_hash, failures, blocked_until, date_upd) VALUES (?, ?, 1, NULL, ?)'
            . ' ON DUPLICATE KEY UPDATE failures = failures + 1,'
            . ' blocked_until = CASE WHEN failures >= ?'
            . ' THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST(?, 60 * POW(2, failures - ?)) SECOND)'
            . ' ELSE NULL END, date_upd = VALUES(date_upd)';

        $this->connection->executeStatement($sql, [
            $scope,
            $subjectHash,
            gmdate('Y-m-d H:i:s'),
            $freeFailures,
            $maxDelaySeconds,
            $freeFailures,
        ]);

        $failures = $this->connection->fetchOne(
            'SELECT failures FROM ' . $this->table() . ' WHERE scope = ? AND subject_hash = ?',
            [$scope, $subjectHash]
        );

        return (int) $failures;
    }

    public function clear(string $scope, string $subjectHash): void
    {
        $this->connection->delete($this->dbPrefix . 'mp2fa_rate_limit', [
            'scope' => $scope,
            'subject_hash' => $subjectHash,
        ]);
    }

    private function table(): string
    {
        return $this->connection->quoteIdentifier($this->dbPrefix . 'mp2fa_rate_limit');
    }
}
