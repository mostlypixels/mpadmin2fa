<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class AdminMfaAccessPolicy
{
    public const ALLOW = 'allow';
    public const REQUIRE_MFA = 'require_mfa';
    public const REQUIRE_STEP_UP = 'require_step_up';
    public const DENY = 'deny';

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

    private const SENSITIVE_ACTION_WORDS = [
        'bulk',
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
        int $employeeId,
        bool $requiresLoginMfa,
        bool $active,
        bool $verified,
        bool $recoveryRestricted,
        bool $freshVerification,
        bool $safeMethod,
        string $route,
        string $legacyController,
        string $action
    ): string {
        if ($employeeId <= 0) {
            return self::DENY;
        }

        if ('admin_logout' === $route) {
            return self::ALLOW;
        }

        if ($recoveryRestricted) {
            return 'mpadmin2fa_enroll' === $route ? self::ALLOW : self::REQUIRE_MFA;
        }

        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return self::ALLOW;
        }

        if (!$active && $requiresLoginMfa) {
            return self::REQUIRE_MFA;
        }

        if ($active && !$verified) {
            return self::REQUIRE_MFA;
        }

        if ($this->isSensitiveRequest($safeMethod, $route, $legacyController, $action)
            && (!$active || !$freshVerification)
        ) {
            return self::REQUIRE_STEP_UP;
        }

        return self::ALLOW;
    }

    public function isSensitiveRequest(
        bool $safeMethod,
        string $route,
        string $legacyController,
        string $action
    ): bool {
        if (!$safeMethod && in_array($route, self::PROTECTED_ROUTES, true)) {
            return true;
        }

        $controller = $this->normalize($legacyController);
        $normalizedAction = $this->normalize($action);
        if ('' === $controller) {
            return false;
        }

        if ('adminimport' === $controller && !$safeMethod) {
            return true;
        }

        if ('' === $normalizedAction) {
            return false;
        }

        if (!in_array($controller, ['adminmodules', 'adminmodulespositions', 'adminthemes', 'adminimport'], true)) {
            return false;
        }

        foreach (self::SENSITIVE_ACTION_WORDS as $word) {
            if (false !== strpos($normalizedAction, $word)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }
}
