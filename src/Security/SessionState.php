<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SessionState
{
    private const KEY = 'mpadmin2fa';

    /** @var SessionInterface */
    private $session;

    public function __construct(SessionInterface $session)
    {
        // PS8 removes the Symfony request before dispatching a legacy controller.
        $this->session = $session;
    }

    public function resetForLogin(int $employeeId): void
    {
        $this->write([
            'employee_id' => $employeeId,
            'authenticated_at' => time(),
            'verified_at' => null,
            'restricted_recovery' => false,
        ]);
    }

    public function markVerified(int $employeeId, bool $recovery = false): void
    {
        $state = $this->read();
        $state['employee_id'] = $employeeId;
        $state['verified_at'] = time();
        $state['restricted_recovery'] = $recovery;
        $this->write($state);
    }

    public function clear(): void
    {
        $this->session->remove(self::KEY);
    }

    public function isVerified(int $employeeId): bool
    {
        $state = $this->read();

        return ($state['employee_id'] ?? null) === $employeeId && is_int($state['verified_at'] ?? null);
    }

    public function hasFreshVerification(int $employeeId, int $maximumAge): bool
    {
        $state = $this->read();

        return $this->isVerified($employeeId) && (int) $state['verified_at'] >= time() - $maximumAge;
    }

    public function isRecoveryRestricted(int $employeeId): bool
    {
        $state = $this->read();

        return ($state['employee_id'] ?? null) === $employeeId && true === ($state['restricted_recovery'] ?? false);
    }

    public function authenticatedAt(int $employeeId): ?int
    {
        $state = $this->read();

        return ($state['employee_id'] ?? null) === $employeeId ? ($state['authenticated_at'] ?? null) : null;
    }

    public function authorizeEnrollmentReplacement(): void
    {
        $state = $this->read();
        $state['replacement_authorized'] = true;
        $this->write($state);
    }

    public function isEnrollmentReplacementAuthorized(int $employeeId): bool
    {
        $state = $this->read();

        return ($state['employee_id'] ?? null) === $employeeId
            && true === ($state['replacement_authorized'] ?? false);
    }

    public function clearEnrollmentReplacementAuthorization(): void
    {
        $state = $this->read();
        unset($state['replacement_authorized']);
        $this->write($state);
    }

    public function setReturnTarget(string $target): void
    {
        $state = $this->read();
        $state['return_target'] = $target;
        $this->write($state);
    }

    public function consumeReturnTarget(): ?string
    {
        $state = $this->read();
        $target = isset($state['return_target']) && is_string($state['return_target'])
            ? $state['return_target']
            : null;
        unset($state['return_target']);
        $this->write($state);

        return $target;
    }

    private function read(): array
    {
        return (array) $this->session->get(self::KEY, []);
    }

    private function write(array $state): void
    {
        $this->session->set(self::KEY, $state);
    }
}
