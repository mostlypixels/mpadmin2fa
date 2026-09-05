<?php

declare(strict_types=1);

namespace Mpadmin2fa\Install;

use Db;
use Defuse\Crypto\KeyProtectedByPassword;
use Mpadmin2fa\Exception\MfaSecurityException;

final class SchemaInstaller
{
    private const TABLES = [
        'mp2fa_audit',
        'mp2fa_rate_limit',
        'mp2fa_approval',
        'mp2fa_recovery_code',
        'mp2fa_employee',
        'mp2fa_keyring',
    ];

    public function install(): bool
    {
        if (!defined('_NEW_COOKIE_KEY_') || !is_string(_NEW_COOKIE_KEY_) || strlen(_NEW_COOKIE_KEY_) < 32) {
            throw new MfaSecurityException('_NEW_COOKIE_KEY_ is missing or too short.');
        }

        foreach ($this->statements() as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        if ((int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_keyring WHERE active = 1'
        ) > 0) {
            return true;
        }

        $protected = KeyProtectedByPassword::createRandomPasswordProtectedKey(_NEW_COOKIE_KEY_);

        return Db::getInstance()->insert('mp2fa_keyring', [
            'version' => 1,
            'protected_key' => pSQL($protected->saveToAsciiSafeString()),
            'cookie_key_fingerprint' => pSQL(hash('sha256', 'mpadmin2fa-cookie-key-v1' . _NEW_COOKIE_KEY_)),
            'active' => 1,
            'date_add' => pSQL(gmdate('Y-m-d H:i:s')),
            'date_upd' => pSQL(gmdate('Y-m-d H:i:s')),
        ]);
    }

    public function uninstall(): bool
    {
        $cleaned = true;
        foreach (self::TABLES as $table) {
            $cleaned = Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . $table) && $cleaned;
        }

        return $cleaned;
    }

    public function upgradeRateLimitTable(): bool
    {
        $column = Db::getInstance()->getValue(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "' . pSQL(_DB_PREFIX_ . 'mp2fa_rate_limit') . '"'
            . ' AND COLUMN_NAME = "last_failure_at"'
        );
        if ('last_failure_at' !== $column && !Db::getInstance()->execute(
            'ALTER TABLE ' . _DB_PREFIX_ . 'mp2fa_rate_limit ADD last_failure_at DATETIME NULL AFTER blocked_until'
        )) {
            return false;
        }

        $legacyColumn = Db::getInstance()->getValue(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "' . pSQL(_DB_PREFIX_ . 'mp2fa_rate_limit') . '"'
            . ' AND COLUMN_NAME = "date_upd"'
        );
        if ('date_upd' !== $legacyColumn) {
            return true;
        }

        // Historical 0.2.7 requires date_upd without a default. Keep its history, then
        // remove it so new counter inserts work. Also repairs a partially applied upgrade.
        return Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'mp2fa_rate_limit SET last_failure_at = date_upd WHERE last_failure_at IS NULL'
        ) && Db::getInstance()->execute(
            'ALTER TABLE ' . _DB_PREFIX_ . 'mp2fa_rate_limit DROP COLUMN date_upd'
        );
    }

    private function statements(): array
    {
        $prefix = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        return [
            'CREATE TABLE IF NOT EXISTS ' . $prefix . 'mp2fa_keyring (
                version INT UNSIGNED NOT NULL,
                protected_key TEXT NOT NULL,
                cookie_key_fingerprint CHAR(64) NOT NULL,
                pending_protected_key TEXT NULL,
                pending_cookie_key_fingerprint CHAR(64) NULL,
                active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                date_add DATETIME NOT NULL,
                date_upd DATETIME NOT NULL,
                PRIMARY KEY (version),
                KEY mp2fa_key_active (active)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS ' . $prefix . 'mp2fa_employee (
                id_employee INT UNSIGNED NOT NULL,
                status ENUM("pending", "active") NOT NULL,
                secret_ciphertext TEXT NOT NULL,
                key_version INT UNSIGNED NOT NULL,
                last_counter BIGINT UNSIGNED NULL,
                confirmed_at DATETIME NULL,
                date_add DATETIME NOT NULL,
                date_upd DATETIME NOT NULL,
                PRIMARY KEY (id_employee),
                CONSTRAINT mp2fa_employee_fk FOREIGN KEY (id_employee)
                    REFERENCES ' . $prefix . 'employee (id_employee) ON DELETE CASCADE,
                CONSTRAINT mp2fa_key_fk FOREIGN KEY (key_version)
                    REFERENCES ' . $prefix . 'mp2fa_keyring (version)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS ' . $prefix . 'mp2fa_recovery_code (
                id_recovery_code BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_employee INT UNSIGNED NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                used_at DATETIME NULL,
                date_add DATETIME NOT NULL,
                PRIMARY KEY (id_recovery_code),
                KEY mp2fa_recovery_employee (id_employee, used_at),
                CONSTRAINT mp2fa_recovery_fk FOREIGN KEY (id_employee)
                    REFERENCES ' . $prefix . 'mp2fa_employee (id_employee) ON DELETE CASCADE
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS ' . $prefix . 'mp2fa_approval (
                id_approval BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_employee INT UNSIGNED NOT NULL,
                requested_by INT UNSIGNED NOT NULL,
                approved_by INT UNSIGNED NULL,
                status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
                date_add DATETIME NOT NULL,
                date_upd DATETIME NOT NULL,
                PRIMARY KEY (id_approval),
                KEY mp2fa_approval_employee (id_employee, status)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS ' . $prefix . 'mp2fa_rate_limit (
                scope VARCHAR(32) NOT NULL,
                subject_hash CHAR(64) NOT NULL,
                failures INT UNSIGNED NOT NULL DEFAULT 0,
                blocked_until DATETIME NULL,
                last_failure_at DATETIME NOT NULL,
                PRIMARY KEY (scope, subject_hash),
                KEY mp2fa_rate_expiry (blocked_until)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=ascii',
            'CREATE TABLE IF NOT EXISTS ' . $prefix . 'mp2fa_audit (
                id_audit BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_employee INT UNSIGNED NULL,
                event VARCHAR(64) NOT NULL,
                ip VARCHAR(45) NULL,
                metadata_json TEXT NOT NULL,
                date_add DATETIME NOT NULL,
                PRIMARY KEY (id_audit),
                KEY mp2fa_audit_employee (id_employee, date_add),
                KEY mp2fa_audit_date (date_add)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
