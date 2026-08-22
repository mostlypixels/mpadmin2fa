<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use PrestaShopBundle\Entity\Employee\Employee;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FactorConfirmationService
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionState $sessionState,
        private readonly Policy $policy,
        private readonly MfaManager $mfa,
    ) {
    }

    /**
     * @param array{password?: string|null, code: string} $data
     */
    public function verify(Employee $employee, array $data, ?string $ip): bool
    {
        if ($this->passwordRequired($employee)
            && !$this->passwordHasher->isPasswordValid($employee, (string) ($data['password'] ?? ''))
        ) {
            return false;
        }

        return $this->mfa->verifyTotp(
            $employee->getId(),
            $data['code'],
            $ip,
            'factor_change'
        );
    }

    public function passwordRequired(Employee $employee): bool
    {
        $authenticatedAt = $this->sessionState->authenticatedAt($employee->getId());

        return !is_int($authenticatedAt)
            || $authenticatedAt < time() - $this->policy->passwordMaximumAge();
    }
}
