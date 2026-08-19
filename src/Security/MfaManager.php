<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Mpadmin2fa\Exception\MfaSecurityException;
use Mpadmin2fa\Repository\SecurityRepository;

final class MfaManager
{
    /** @var SecurityRepository */
    private $repository;

    /** @var KeyManager */
    private $keys;

    /** @var TotpService */
    private $totp;

    /** @var RecoveryCodeService */
    private $recoveryCodes;

    /** @var RateLimiter */
    private $rateLimiter;

    /** @var SecurityAlertService */
    private $alerts;

    public function __construct(
        SecurityRepository $repository,
        KeyManager $keys,
        TotpService $totp,
        RecoveryCodeService $recoveryCodes,
        RateLimiter $rateLimiter,
        SecurityAlertService $alerts)
    {
        $this->repository = $repository;
        $this->keys = $keys;
        $this->totp = $totp;
        $this->recoveryCodes = $recoveryCodes;
        $this->rateLimiter = $rateLimiter;
        $this->alerts = $alerts;
    }

    public function beginEnrollment(int $employeeId): string
    {
        $secret = $this->totp->generateSecret();
        $encrypted = $this->keys->encrypt($secret);
        $this->repository->savePendingEnrollment($employeeId, $encrypted['ciphertext'], $encrypted['key_version']);

        return $secret;
    }

    public function pendingSecret(int $employeeId): string
    {
        $factor = $this->repository->factor($employeeId);
        if (!$factor || 'pending' !== $factor['status']) {
            throw new MfaSecurityException('No pending enrollment exists.');
        }

        return $this->keys->decrypt((string) $factor['secret_ciphertext'], (int) $factor['key_version']);
    }

    public function confirmEnrollment(int $employeeId, string $code, ?string $ip): array
    {
        $this->rateLimiter->assertAllowed('enrollment', $employeeId, $ip);
        $factor = $this->repository->factor($employeeId);
        if (!$factor || 'pending' !== $factor['status']) {
            throw new MfaSecurityException('No pending enrollment exists.');
        }

        $secret = $this->keys->decrypt((string) $factor['secret_ciphertext'], (int) $factor['key_version']);
        $counter = $this->totp->verifyNewer($secret, trim($code), null);
        if (false === $counter) {
            $failures = $this->rateLimiter->failure('enrollment', $employeeId, $ip);
            $this->notifyRepeatedFailures($employeeId, 'enrollment', $failures);
            $this->repository->audit($employeeId, 'enrollment.failed', $ip);
            throw new MfaSecurityException('The authentication code is invalid.');
        }

        $codes = $this->recoveryCodes->generate();
        $this->repository->activateEnrollment($employeeId, $counter, $this->recoveryCodes->hashes($codes));
        $this->rateLimiter->success('enrollment', $employeeId, $ip);
        $this->repository->audit($employeeId, 'enrollment.confirmed', $ip);
        $this->alerts->notify($employeeId, 'enrollment.confirmed');

        return $codes;
    }

    public function verifyTotp(int $employeeId, string $code, ?string $ip, string $scope = 'challenge'): bool
    {
        $this->rateLimiter->assertAllowed($scope, $employeeId, $ip);
        $factor = $this->repository->factor($employeeId);
        if (!$factor || 'active' !== $factor['status']) {
            throw new MfaSecurityException('TOTP is not active for this employee.');
        }

        $secret = $this->keys->decrypt((string) $factor['secret_ciphertext'], (int) $factor['key_version']);
        $previous = null === $factor['last_counter'] ? null : (int) $factor['last_counter'];
        $counter = $this->totp->verifyNewer($secret, trim($code), $previous);
        if (false === $counter || !$this->repository->advanceCounter($employeeId, $previous, $counter)) {
            $failures = $this->rateLimiter->failure($scope, $employeeId, $ip);
            $this->notifyRepeatedFailures($employeeId, $scope, $failures);
            $this->repository->audit($employeeId, $scope . '.failed', $ip);

            return false;
        }

        $this->rateLimiter->success($scope, $employeeId, $ip);
        $this->repository->audit($employeeId, $scope . '.verified', $ip);

        return true;
    }

    public function useRecoveryCode(int $employeeId, string $code, ?string $ip): bool
    {
        $this->rateLimiter->assertAllowed('recovery', $employeeId, $ip);
        $valid = $this->repository->consumeRecoveryCode($employeeId, $this->recoveryCodes->normalize($code));
        if (!$valid) {
            $failures = $this->rateLimiter->failure('recovery', $employeeId, $ip);
            $this->notifyRepeatedFailures($employeeId, 'recovery', $failures);
            $this->repository->audit($employeeId, 'recovery.failed', $ip);

            return false;
        }

        $this->rateLimiter->success('recovery', $employeeId, $ip);
        $this->repository->audit($employeeId, 'recovery.used', $ip);
        $this->alerts->notify($employeeId, 'recovery.used');

        return true;
    }

    public function active(int $employeeId): bool
    {
        return 'active' === ($this->repository->factor($employeeId)['status'] ?? null);
    }

    public function regenerateRecoveryCodes(int $employeeId, ?string $ip): array
    {
        $codes = $this->recoveryCodes->generate();
        $this->repository->replaceRecoveryCodes($employeeId, $this->recoveryCodes->hashes($codes));
        $this->repository->audit($employeeId, 'recovery.regenerated', $ip);
        $this->alerts->notify($employeeId, 'recovery.regenerated');

        return $codes;
    }

    public function reset(int $employeeId, ?int $actorId, ?string $ip, string $reason): void
    {
        $this->repository->resetEmployee($employeeId);
        $this->repository->audit($actorId, 'factor.reset', $ip, [
            'target_employee_id' => $employeeId,
            'reason' => $reason,
        ]);
        $this->alerts->notify($employeeId, 'factor.reset', ['reason' => $reason]);
    }

    private function notifyRepeatedFailures(int $employeeId, string $scope, int $failures): void
    {
        if (5 === $failures || ($failures > 5 && 0 === $failures % 3)) {
            $this->alerts->notify($employeeId, 'authentication.repeated_failures', [
                'scope' => $scope,
                'failures' => $failures,
            ]);
        }
    }
}
