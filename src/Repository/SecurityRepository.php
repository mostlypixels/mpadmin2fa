<?php

declare(strict_types=1);

namespace Mpadmin2fa\Repository;

use Doctrine\DBAL\Connection;
use Mpadmin2fa\Exception\MfaSecurityException;

final class SecurityRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function activeKey(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $this->table('keyring') . ' WHERE active = 1 ORDER BY version DESC LIMIT 1'
        );

        return $row ?: null;
    }

    public function createInitialKey(string $protectedKey, string $fingerprint): void
    {
        $this->connection->insert($this->tableName('keyring'), [
            'version' => 1,
            'protected_key' => $protectedKey,
            'cookie_key_fingerprint' => $fingerprint,
            'active' => 1,
            'date_add' => $this->now(),
            'date_upd' => $this->now(),
        ]);
    }

    public function stageRewrappedKey(int $version, string $protectedKey, string $fingerprint): void
    {
        $this->connection->update($this->tableName('keyring'), [
            'pending_protected_key' => $protectedKey,
            'pending_cookie_key_fingerprint' => $fingerprint,
            'date_upd' => $this->now(),
        ], ['version' => $version, 'active' => 1]);
    }

    public function commitRewrappedKey(int $version): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE ' . $this->table('keyring') . ' SET protected_key = pending_protected_key, '
            . 'cookie_key_fingerprint = pending_cookie_key_fingerprint, pending_protected_key = NULL, '
            . 'pending_cookie_key_fingerprint = NULL, date_upd = ? '
            . 'WHERE version = ? AND active = 1 AND pending_protected_key IS NOT NULL',
            [$this->now(), $version]
        );
        if (1 !== $updated) {
            throw new MfaSecurityException('No prepared key rotation was available to commit.');
        }
    }

    public function savePendingEnrollment(int $employeeId, string $ciphertext, int $keyVersion): void
    {
        $sql = 'INSERT INTO ' . $this->table('employee')
            . ' (id_employee, status, secret_ciphertext, key_version, last_counter, confirmed_at, date_add, date_upd)'
            . ' VALUES (?, "pending", ?, ?, NULL, NULL, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE status = "pending", secret_ciphertext = VALUES(secret_ciphertext),'
            . ' key_version = VALUES(key_version), last_counter = NULL, confirmed_at = NULL, date_upd = VALUES(date_upd)';
        $now = $this->now();
        $this->connection->executeStatement($sql, [$employeeId, $ciphertext, $keyVersion, $now, $now]);
    }

    public function factor(int $employeeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $this->table('employee') . ' WHERE id_employee = ?',
            [$employeeId]
        );

        return $row ?: null;
    }

    public function activateEnrollment(int $employeeId, int $counter, array $recoveryHashes): void
    {
        $this->connection->transactional(function () use ($employeeId, $counter, $recoveryHashes): void {
            $updated = $this->connection->executeStatement(
                'UPDATE ' . $this->table('employee')
                . ' SET status = "active", last_counter = ?, confirmed_at = ?, date_upd = ?'
                . ' WHERE id_employee = ? AND status = "pending"',
                [$counter, $this->now(), $this->now(), $employeeId]
            );
            if (1 !== $updated) {
                throw new MfaSecurityException('The enrollment is no longer pending.');
            }

            $this->connection->delete($this->tableName('recovery_code'), ['id_employee' => $employeeId]);
            foreach ($recoveryHashes as $hash) {
                $this->connection->insert($this->tableName('recovery_code'), [
                    'id_employee' => $employeeId,
                    'code_hash' => $hash,
                    'used_at' => null,
                    'date_add' => $this->now(),
                ]);
            }
        });
    }

    public function advanceCounter(int $employeeId, ?int $previousCounter, int $newCounter): bool
    {
        if (null === $previousCounter) {
            $sql = 'UPDATE ' . $this->table('employee')
                . ' SET last_counter = ?, date_upd = ? WHERE id_employee = ? AND status = "active" AND last_counter IS NULL';
            $params = [$newCounter, $this->now(), $employeeId];
        } else {
            $sql = 'UPDATE ' . $this->table('employee')
                . ' SET last_counter = ?, date_upd = ? WHERE id_employee = ? AND status = "active" AND last_counter = ?';
            $params = [$newCounter, $this->now(), $employeeId, $previousCounter];
        }

        return 1 === $this->connection->executeStatement($sql, $params);
    }

    public function consumeRecoveryCode(int $employeeId, string $code): bool
    {
        return $this->connection->transactional(function () use ($employeeId, $code): bool {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id_recovery_code, code_hash FROM ' . $this->table('recovery_code')
                . ' WHERE id_employee = ? AND used_at IS NULL FOR UPDATE',
                [$employeeId]
            );
            foreach ($rows as $row) {
                if (password_verify($code, (string) $row['code_hash'])) {
                    return 1 === $this->connection->executeStatement(
                        'UPDATE ' . $this->table('recovery_code')
                        . ' SET used_at = ? WHERE id_recovery_code = ? AND used_at IS NULL',
                        [$this->now(), (int) $row['id_recovery_code']]
                    );
                }
            }

            return false;
        });
    }

    public function replaceRecoveryCodes(int $employeeId, array $hashes): void
    {
        $this->connection->transactional(function () use ($employeeId, $hashes): void {
            $this->connection->delete($this->tableName('recovery_code'), ['id_employee' => $employeeId]);
            foreach ($hashes as $hash) {
                $this->connection->insert($this->tableName('recovery_code'), [
                    'id_employee' => $employeeId,
                    'code_hash' => $hash,
                    'used_at' => null,
                    'date_add' => $this->now(),
                ]);
            }
        });
    }

    public function resetEmployee(int $employeeId): void
    {
        $this->connection->transactional(function () use ($employeeId): void {
            $this->connection->delete($this->tableName('recovery_code'), ['id_employee' => $employeeId]);
            $this->connection->delete($this->tableName('approval'), ['id_employee' => $employeeId]);
            $this->connection->delete($this->tableName('employee'), ['id_employee' => $employeeId]);
        });
    }

    public function rateLimit(string $scope, string $subjectHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $this->table('rate_limit') . ' WHERE scope = ? AND subject_hash = ?',
            [$scope, $subjectHash]
        );

        return $row ?: null;
    }

    public function recordFailure(string $scope, string $subjectHash, int $failures, ?string $blockedUntil): void
    {
        $sql = 'INSERT INTO ' . $this->table('rate_limit')
            . ' (scope, subject_hash, failures, blocked_until, date_upd) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE failures = VALUES(failures), blocked_until = VALUES(blocked_until), date_upd = VALUES(date_upd)';
        $this->connection->executeStatement($sql, [$scope, $subjectHash, $failures, $blockedUntil, $this->now()]);
    }

    public function clearFailures(string $scope, string $subjectHash): void
    {
        $this->connection->delete($this->tableName('rate_limit'), ['scope' => $scope, 'subject_hash' => $subjectHash]);
    }

    public function audit(?int $employeeId, string $event, ?string $ip, array $metadata = []): void
    {
        $this->connection->insert($this->tableName('audit'), [
            'id_employee' => $employeeId,
            'event' => $event,
            'ip' => $ip,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'date_add' => $this->now(),
        ]);
    }

    public function pruneAudit(int $days): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM ' . $this->table('audit') . ' WHERE date_add < ?',
            [gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)]
        );
    }

    public function hasActiveSuperAdminFactor(int $superAdminProfileId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM ' . $this->table('employee') . ' f INNER JOIN ' . $this->dbPrefix
            . 'employee e ON e.id_employee = f.id_employee WHERE f.status = "active" AND e.id_profile = ? LIMIT 1',
            [$superAdminProfileId]
        );
    }

    public function enrollmentApprovalStatus(int $employeeId): ?string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM ' . $this->table('approval')
            . ' WHERE id_employee = ? ORDER BY id_approval DESC LIMIT 1',
            [$employeeId]
        );

        return false === $status ? null : (string) $status;
    }

    public function requestEnrollmentApproval(int $employeeId): void
    {
        if ('pending' === $this->enrollmentApprovalStatus($employeeId)) {
            return;
        }

        $this->connection->insert($this->tableName('approval'), [
            'id_employee' => $employeeId,
            'requested_by' => $employeeId,
            'approved_by' => null,
            'status' => 'pending',
            'date_add' => $this->now(),
            'date_upd' => $this->now(),
        ]);
    }

    public function approveEnrollment(int $employeeId, int $approverId): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE ' . $this->table('approval')
            . ' SET status = "approved", approved_by = ?, date_upd = ?'
            . ' WHERE id_employee = ? AND status = "pending"',
            [$approverId, $this->now(), $employeeId]
        );
        if (1 !== $updated) {
            throw new MfaSecurityException('No pending enrollment approval was found.');
        }
    }

    public function pendingApprovals(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id_employee, a.date_add, e.email, e.firstname, e.lastname, e.id_profile'
            . ' FROM ' . $this->table('approval') . ' a INNER JOIN ' . $this->dbPrefix
            . 'employee e ON e.id_employee = a.id_employee WHERE a.status = "pending" ORDER BY a.date_add'
        );
    }

    public function employeeEmail(int $employeeId): ?string
    {
        $email = $this->connection->fetchOne(
            'SELECT email FROM ' . $this->dbPrefix . 'employee WHERE id_employee = ?',
            [$employeeId]
        );

        return false === $email ? null : (string) $email;
    }

    public function employeeIdByEmail(string $email): ?int
    {
        $id = $this->connection->fetchOne(
            'SELECT id_employee FROM ' . $this->dbPrefix . 'employee WHERE email = ?',
            [$email]
        );

        return false === $id ? null : (int) $id;
    }

    public function employeeStatuses(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT e.id_employee, e.email, e.firstname, e.lastname, e.id_profile, f.status, f.confirmed_at '
            . 'FROM ' . $this->dbPrefix . 'employee e LEFT JOIN ' . $this->table('employee')
            . ' f ON f.id_employee = e.id_employee ORDER BY e.lastname, e.firstname'
        );
    }

    public function auditEvents(int $limit = 100): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . $this->table('audit') . ' ORDER BY id_audit DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    private function table(string $suffix): string
    {
        return $this->connection->quoteIdentifier($this->tableName($suffix));
    }

    private function tableName(string $suffix): string
    {
        return $this->dbPrefix . 'mp2fa_' . $suffix;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
