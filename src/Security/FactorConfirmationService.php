<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use PrestaShopBundle\Security\Admin\Employee;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

final class FactorConfirmationService
{

    /** @var UserPasswordEncoderInterface */
    private $passwordEncoder;

    /** @var SessionState */
    private $sessionState;

    /** @var Policy */
    private $policy;

    /** @var MfaManager */
    private $mfa;

    public function __construct(
        UserPasswordEncoderInterface $passwordEncoder,
        SessionState $sessionState,
        Policy $policy,
        MfaManager $mfa
    ) {
        $this->passwordEncoder = $passwordEncoder;
        $this->sessionState = $sessionState;
        $this->policy = $policy;
        $this->mfa = $mfa;
    }

    /**
     * @param array{password?: string|null, code: string} $data
     */
    public function verify(Employee $employee, array $data, ?string $ip): bool
    {
        if ($this->passwordRequired($employee)
            && !$this->passwordEncoder->isPasswordValid($employee, (string) ($data['password'] ?? ''))
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
