<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use PrestaShopBundle\Security\Admin\Employee;

final class Policy
{
    public const CONFIG_MODE = 'MP2FA_ENFORCEMENT_MODE';
    public const CONFIG_PROFILES = 'MP2FA_ENFORCED_PROFILES';
    public const CONFIG_STEP_UP_SECONDS = 'MP2FA_STEP_UP_SECONDS';
    public const CONFIG_PASSWORD_MAX_AGE = 'MP2FA_PASSWORD_MAX_AGE';
    public const CONFIG_AUDIT_DAYS = 'MP2FA_AUDIT_DAYS';
    public const CONFIG_APPROVAL_PROFILES = 'MP2FA_APPROVAL_PROFILES';
    public const CONFIG_SECURITY_RECIPIENTS = 'MP2FA_SECURITY_RECIPIENTS';

    public function __construct(private readonly ConfigurationInterface $configuration)
    {
    }

    public function requiresLoginMfa(Employee $employee): bool
    {
        $mode = (string) ($this->configuration->get(self::CONFIG_MODE) ?: 'superadmins');
        if ('all' === $mode) {
            return true;
        }

        if ('profiles' === $mode) {
            $profiles = array_filter(array_map('intval', explode(',', (string) $this->configuration->get(self::CONFIG_PROFILES))));

            return in_array((int) $employee->getData()->id_profile, $profiles, true);
        }

        return (int) $employee->getData()->id_profile === (defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1);
    }

    public function requiresEnrollmentApproval(Employee $employee): bool
    {
        $superAdmin = defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1;
        $profiles = array_filter(array_map(
            'intval',
            explode(',', (string) $this->configuration->get(self::CONFIG_APPROVAL_PROFILES))
        ));

        return (int) $employee->getData()->id_profile === $superAdmin || in_array((int) $employee->getData()->id_profile, $profiles, true);
    }

    public function stepUpSeconds(): int
    {
        return max(60, (int) ($this->configuration->get(self::CONFIG_STEP_UP_SECONDS) ?: 300));
    }

    public function passwordMaximumAge(): int
    {
        return max(60, (int) ($this->configuration->get(self::CONFIG_PASSWORD_MAX_AGE) ?: 900));
    }

    public function auditDays(): int
    {
        return max(1, (int) ($this->configuration->get(self::CONFIG_AUDIT_DAYS) ?: 90));
    }
}
