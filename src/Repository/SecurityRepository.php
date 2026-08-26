<?php

declare(strict_types=1);

namespace Mpadmin2fa\Repository;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Mpadmin2fa\Exception\MfaSecurityException;

final class SecurityRepository
{

    /** @var Connection */
    private $connection;

    /** @var string */
    private $dbPrefix;

    private const IMPORTANT_DASHBOARD_EVENTS = [
        'enrollment.failed',
        'enrollment.approved',
        'challenge.failed',
        'step_up.failed',
        'factor_change.failed',
        'recovery.failed',
        'recovery.used',
        'factor.reset',
        'policy.updated',
    ];

    public function __construct(
        Connection $connection,
        string $dbPrefix
    ) {
        $this->connection = $connection;
        $this->dbPrefix = $dbPrefix;
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
                throw new MfaSecurityException('This 2FA setup is no longer waiting for approval.');
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
            'metadata_json' => json_encode($metadata),
            'date_add' => $this->now(),
        ]);
    }

    /**
     * @return array{failures: int, first_failure_at: string|null}
     */
    public function challengeFailuresSinceLastSuccess(int $employeeId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS failures, MIN(date_add) AS first_failure_at FROM ' . $this->table('audit')
            . ' WHERE id_employee = ? AND event = ? AND id_audit > COALESCE(('
            . 'SELECT MAX(id_audit) FROM ' . $this->table('audit') . ' WHERE id_employee = ? AND event = ?'
            . '), 0)',
            [$employeeId, 'challenge.failed', $employeeId, 'challenge.verified']
        );

        return [
            'failures' => (int) ($row['failures'] ?? 0),
            'first_failure_at' => isset($row['first_failure_at']) ? (string) $row['first_failure_at'] : null,
        ];
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

    /**
     * @return array{not_enrolled: int, total: int}
     */
    public function activeEmployeeEnrollmentSummary(): array
    {
        $employeeTable = $this->connection->quoteIdentifier($this->dbPrefix . 'employee');
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(e.id_employee) AS total, '
            . 'COALESCE(SUM(CASE WHEN f.status = "active" THEN 0 ELSE 1 END), 0) AS not_enrolled '
            . 'FROM ' . $employeeTable . ' e LEFT JOIN ' . $this->table('employee') . ' f '
            . 'ON f.id_employee = e.id_employee WHERE e.active = 1'
        );

        return [
            'not_enrolled' => (int) ($row['not_enrolled'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * Return grouped security-significant audit events for the dashboard.
     *
     * Events are identical when their event, employee, and metadata match.
     *
     * @return list<array{
     *     date_add: string,
     *     employee: string,
     *     event_label: string,
     *     occurrences: int
     * }>
     */
    public function importantDashboardEventsSince(DateTimeInterface $since): array
    {
        $employeeTable = $this->connection->quoteIdentifier($this->dbPrefix . 'employee');
        $placeholders = implode(', ', array_fill(0, count(self::IMPORTANT_DASHBOARD_EVENTS), '?'));
        $utcSince = (new DateTimeImmutable('@' . $since->getTimestamp()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT MAX(a.date_add) AS date_add, '
            . 'CASE'
            . ' WHEN a.id_employee IS NULL THEN "System"'
            . ' WHEN e.id_employee IS NULL THEN CONCAT(a.id_employee, " - Deleted employee")'
            . ' ELSE CONCAT(e.firstname, " ", e.lastname)'
            . ' END AS employee, '
            . 'CASE a.event'
            . ' WHEN "enrollment.failed" THEN "Authenticator setup failed"'
            . ' WHEN "enrollment.approved" THEN "2FA setup approved"'
            . ' WHEN "challenge.failed" THEN "Sign-in 2FA failed"'
            . ' WHEN "step_up.failed" THEN "Security-change 2FA failed"'
            . ' WHEN "factor_change.failed" THEN "Authenticator-settings check failed"'
            . ' WHEN "recovery.failed" THEN "Recovery code rejected"'
            . ' WHEN "recovery.used" THEN "Recovery code used"'
            . ' WHEN "factor.reset" THEN "Two-factor authentication reset"'
            . ' WHEN "policy.updated" THEN "Two-factor authentication settings changed"'
            . ' ELSE a.event END AS event_label, '
            . 'COUNT(*) AS occurrences '
            . 'FROM ' . $this->table('audit') . ' a '
            . 'LEFT JOIN ' . $employeeTable . ' e ON e.id_employee = a.id_employee '
            . 'WHERE a.date_add >= ? AND a.event IN (' . $placeholders . ') '
            . 'GROUP BY a.id_employee, a.event, a.metadata_json, e.id_employee, e.firstname, e.lastname '
            . 'ORDER BY date_add DESC',
            array_merge([$utcSince], self::IMPORTANT_DASHBOARD_EVENTS)
        );

        return array_map(static function (array $row): array {
            return [
                'date_add' => (string) $row['date_add'],
                'employee' => (string) $row['employee'],
                'event_label' => (string) $row['event_label'],
                'occurrences' => (int) $row['occurrences'],
            ];
        }, $rows);
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
            throw new MfaSecurityException('No 2FA setup waiting for approval was found.');
        }
    }

    public function employeeEmail(int $employeeId): ?string
    {
        $email = $this->connection->fetchOne(
            'SELECT email FROM ' . $this->dbPrefix . 'employee WHERE id_employee = ?',
            [$employeeId]
        );

        return false === $email ? null : (string) $email;
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public function employeeIdentity(int $employeeId): ?array
    {
        $employee = $this->connection->fetchAssociative(
            'SELECT firstname, lastname, email FROM ' . $this->dbPrefix . 'employee WHERE id_employee = ?',
            [$employeeId]
        );
        if (false === $employee) {
            return null;
        }

        $name = trim((string) $employee['firstname'] . ' ' . (string) $employee['lastname']);

        return [
            'name' => '' !== $name ? $name : 'Employee #' . $employeeId,
            'email' => (string) $employee['email'],
        ];
    }

    public function employeeIdByEmail(string $email): ?int
    {
        $id = $this->connection->fetchOne(
            'SELECT id_employee FROM ' . $this->dbPrefix . 'employee WHERE email = ?',
            [$email]
        );

        return false === $id ? null : (int) $id;
    }

    /**
     * @return array<string, int>
     */
    public function profileChoices(int $languageId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id_profile, pl.name FROM ' . $this->dbPrefix . 'profile p INNER JOIN '
            . $this->dbPrefix . 'profile_lang pl ON pl.id_profile = p.id_profile AND pl.id_lang = ? ORDER BY pl.name',
            [$languageId]
        );
        $choices = [];
        foreach ($rows as $row) {
            $label = (string) $row['name'];
            if (array_key_exists($label, $choices)) {
                $label .= ' (#' . (int) $row['id_profile'] . ')';
            }
            $choices[$label] = (int) $row['id_profile'];
        }

        return $choices;
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
