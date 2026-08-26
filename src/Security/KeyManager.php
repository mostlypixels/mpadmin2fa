<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\KeyProtectedByPassword;
use Mpadmin2fa\Exception\MfaSecurityException;
use Mpadmin2fa\Repository\SecurityRepository;
use Throwable;

final class KeyManager
{
    /** @var SecurityRepository */
    private $repository;

    /** @var CookieKeyProvider */
    private $cookieKeys;

    /** @var ProtectedKeyRewrapper */
    private $keyRewrapper;

    public function __construct(
        SecurityRepository $repository,
        CookieKeyProvider $cookieKeys,
        ProtectedKeyRewrapper $keyRewrapper
    )
    {
        $this->repository = $repository;
        $this->cookieKeys = $cookieKeys;
        $this->keyRewrapper = $keyRewrapper;
    }

    public function initialize(): void
    {
        if (null !== $this->repository->activeKey()) {
            return;
        }

        $cookieKey = $this->cookieKeys->current();
        $protected = KeyProtectedByPassword::createRandomPasswordProtectedKey($cookieKey);
        $this->repository->createInitialKey(
            $protected->saveToAsciiSafeString(),
            $this->cookieKeys->fingerprint($cookieKey)
        );
    }

    public function encrypt(string $plaintext): array
    {
        $row = $this->requireActiveRow();

        return [
            'ciphertext' => Crypto::encrypt($plaintext, $this->unlock($row)),
            'key_version' => (int) $row['version'],
        ];
    }

    public function decrypt(string $ciphertext, int $version): string
    {
        $row = $this->requireActiveRow();
        if ((int) $row['version'] !== $version) {
            throw new MfaSecurityException('The requested data-encryption key version is unavailable.');
        }

        try {
            return Crypto::decrypt($ciphertext, $this->unlock($row));
        } catch (Throwable $exception) {
            throw new MfaSecurityException('The encrypted TOTP secret could not be authenticated.', 0, $exception);
        }
    }

    public function health(): array
    {
        try {
            $row = $this->requireActiveRow();
            $currentCookieKey = $this->cookieKeys->current();
            try {
                $this->unlock($row);

                return [
                    'healthy' => true,
                    'version' => (int) $row['version'],
                    'state' => 'active',
                    'fingerprint_matches' => hash_equals(
                        (string) $row['cookie_key_fingerprint'],
                        $this->cookieKeys->fingerprint($currentCookieKey)
                    ),
                    'rotation_prepared' => !empty($row['pending_protected_key']),
                ];
            } catch (MfaSecurityException $activeFailure) {
                if (empty($row['pending_protected_key'])
                    || !hash_equals(
                        (string) $row['pending_cookie_key_fingerprint'],
                        $this->cookieKeys->fingerprint($currentCookieKey)
                    )
                ) {
                    throw $activeFailure;
                }

                KeyProtectedByPassword::loadFromAsciiSafeString((string) $row['pending_protected_key'])
                    ->unlockKey($currentCookieKey);

                return [
                    'healthy' => true,
                    'version' => (int) $row['version'],
                    'state' => 'prepared_rotation_ready_to_commit',
                    'fingerprint_matches' => true,
                    'rotation_prepared' => true,
                ];
            }
        } catch (Throwable $exception) {
            return ['healthy' => false, 'error' => $exception->getMessage()];
        }
    }

    public function prepareRotation(string $newCookieKey): void
    {
        if (strlen($newCookieKey) < 32) {
            throw new MfaSecurityException('The new cookie key is too short.');
        }

        $row = $this->requireActiveRow();
        $protected = KeyProtectedByPassword::loadFromAsciiSafeString((string) $row['protected_key']);
        $protected = $this->keyRewrapper->rewrap(
            $protected,
            $this->cookieKeys->current(),
            $newCookieKey
        );
        $this->repository->stageRewrappedKey(
            (int) $row['version'],
            $protected->saveToAsciiSafeString(),
            $this->cookieKeys->fingerprint($newCookieKey)
        );
    }

    public function commitPreparedRotation(): void
    {
        $row = $this->requireActiveRow();
        if (empty($row['pending_protected_key'])) {
            throw new MfaSecurityException('No key rotation has been prepared.');
        }

        $candidate = KeyProtectedByPassword::loadFromAsciiSafeString((string) $row['pending_protected_key']);
        $candidate->unlockKey($this->cookieKeys->current());
        if (!hash_equals(
            (string) $row['pending_cookie_key_fingerprint'],
            $this->cookieKeys->fingerprint($this->cookieKeys->current())
        )) {
            throw new MfaSecurityException('The configured cookie key does not match the prepared rotation.');
        }

        $this->repository->commitRewrappedKey((int) $row['version']);
    }

    private function requireActiveRow(): array
    {
        $row = $this->repository->activeKey();
        if (null === $row) {
            throw new MfaSecurityException('The module data-encryption key is missing.');
        }

        return $row;
    }

    private function unlock(array $row): Key
    {
        try {
            return KeyProtectedByPassword::loadFromAsciiSafeString((string) $row['protected_key'])
                ->unlockKey($this->cookieKeys->current());
        } catch (Throwable $exception) {
            throw new MfaSecurityException('The module data-encryption key cannot be unlocked.', 0, $exception);
        }
    }
}
