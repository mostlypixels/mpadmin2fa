<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class AdminMfaAccessPolicy
{
    public const ALLOW = 'allow';
    public const REQUIRE_MFA = 'require_mfa';
    public const REQUIRE_STEP_UP = 'require_step_up';
    public const DENY = 'deny';

    private const ALLOWED_LEGACY_CONTROLLERS = [
        'AdminLogin',
        'AdminMpAdmin2fa',
        'AdminMpAdmin2faAuthenticator',
        'AdminMpAdmin2faEnrollment',
        'AdminMpAdmin2faSecurity',
        'AdminMpAdmin2faSecurityActivity',
    ];

    private const ALLOWED_ROUTES = [
        'admin_logout',
        'mpadmin2fa_challenge',
        'mpadmin2fa_disable',
        'mpadmin2fa_enroll',
        'mpadmin2fa_recovery_codes',
        'mpadmin2fa_replace',
    ];

    private const PROTECTED_ROUTES = [
        'admin_module_configure_action',
        'admin_module_import',
        'admin_module_manage_action',
        'admin_module_manage_action_bulk',
        'admin_module_manage_update_all',
        'admin_themes_enable',
        'admin_themes_import',
        'mpadmin2fa_admin_reset',
        'mpadmin2fa_approve',
        'mpadmin2fa_security_policy_update',
    ];

    private const SENSITIVE_ACTION_PARTS = [
        'bulk',
        'configure',
        'delete',
        'disable',
        'enable',
        'import',
        'install',
        'reset',
        'uninstall',
        'update',
        'upgrade',
    ];

    public function decide(
        string $entryPoint,
        string $resource,
        string $action,
        bool $methodSafe,
        bool $mfaRequired,
        bool $active,
        bool $verified,
        bool $freshVerification,
        bool $recoveryRestricted
    ): string {
        if ('symfony' !== $entryPoint && 'legacy' !== $entryPoint) {
            return self::DENY;
        }

        if ($this->isLogout($entryPoint, $resource, $action)) {
            return self::ALLOW;
        }

        if ($recoveryRestricted) {
            return $this->isEnrollmentEndpoint($entryPoint, $resource)
                ? self::ALLOW
                : self::REQUIRE_MFA;
        }

        if ($this->isAllowedEndpoint($entryPoint, $resource)) {
            return self::ALLOW;
        }

        if (!$active && $mfaRequired) {
            return self::REQUIRE_MFA;
        }

        if ($active && !$verified) {
            return self::REQUIRE_MFA;
        }

        if ($this->isSensitive($entryPoint, $resource, $action, $methodSafe)
            && (!$active || !$freshVerification)
        ) {
            return self::REQUIRE_STEP_UP;
        }

        return self::ALLOW;
    }

    private function isAllowedEndpoint(string $entryPoint, string $resource): bool
    {
        if ('symfony' === $entryPoint) {
            return in_array($resource, self::ALLOWED_ROUTES, true);
        }

        return in_array($resource, self::ALLOWED_LEGACY_CONTROLLERS, true);
    }

    private function isEnrollmentEndpoint(string $entryPoint, string $resource): bool
    {
        return ('symfony' === $entryPoint && 'mpadmin2fa_enroll' === $resource)
            || ('legacy' === $entryPoint && 'AdminMpAdmin2faAuthenticator' === $resource);
    }

    private function isLogout(string $entryPoint, string $resource, string $action): bool
    {
        return ('symfony' === $entryPoint && 'admin_logout' === $resource)
            || ('legacy' === $entryPoint && 'AdminLogin' === $resource && 'logout' === $action);
    }

    private function isSensitive(
        string $entryPoint,
        string $resource,
        string $action,
        bool $methodSafe
    ): bool {
        if ('symfony' === $entryPoint) {
            return !$methodSafe && in_array($resource, self::PROTECTED_ROUTES, true);
        }

        if ('AdminModules' === $resource && !$methodSafe) {
            return true;
        }

        if (in_array($resource, ['AdminImport', 'AdminThemes'], true) && !$methodSafe) {
            return true;
        }

        if (!in_array($resource, ['AdminImport', 'AdminModules', 'AdminThemes'], true)) {
            return false;
        }

        foreach (self::SENSITIVE_ACTION_PARTS as $part) {
            if (false !== strpos($action, $part)) {
                return true;
            }
        }

        return false;
    }
}
