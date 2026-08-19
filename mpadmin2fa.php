<?php

declare(strict_types=1);

/**
 * Mpadmin2fa
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (is_file(__DIR__ . '/vendor-scoped/autoload.php')) {
    require_once __DIR__ . '/vendor-scoped/autoload.php';
} elseif (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Mpadmin2fa extends Module
{
    public function __construct()
    {
        $this->name = 'mpadmin2fa';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'Cindy Durand';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Admin 2FA', [], 'Modules.Mpadmin2fa.Admin');
        $this->description = $this->trans(
            'TOTP two-factor authentication and step-up protection for sensitive back-office operations.',
            [],
            'Modules.Mpadmin2fa.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling permanently removes all enrolled factors, recovery codes, encryption keys, policy, and audit records.',
            [],
            'Modules.Mpadmin2fa.Admin'
        );
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => '1.7.99.99'];
    }

    public function install(): bool
    {
        if (!is_file(__DIR__ . '/vendor/autoload.php') && !is_file(__DIR__ . '/vendor-scoped/autoload.php')) {
            $this->_errors[] = $this->trans('The production dependencies are missing.', [], 'Modules.Mpadmin2fa.Admin');

            return false;
        }

        try {
            $installed = parent::install()
                && (new Mpadmin2fa\Install\SchemaInstaller())->install()
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_MODE, 'superadmins')
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_PROFILES, '')
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_STEP_UP_SECONDS, 300)
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_PASSWORD_MAX_AGE, 900)
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_AUDIT_DAYS, 90)
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_APPROVAL_PROFILES, '')
                && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_SECURITY_RECIPIENTS, '');
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            $installed = false;
        }

        if (!$installed) {
            (new Mpadmin2fa\Install\SchemaInstaller())->uninstall();
            parent::uninstall();
        }

        return $installed;
    }

    public function uninstall(): bool
    {
        $cleaned = (new Mpadmin2fa\Install\SchemaInstaller())->uninstall();
        foreach ([
            Mpadmin2fa\Security\Policy::CONFIG_MODE,
            Mpadmin2fa\Security\Policy::CONFIG_PROFILES,
            Mpadmin2fa\Security\Policy::CONFIG_STEP_UP_SECONDS,
            Mpadmin2fa\Security\Policy::CONFIG_PASSWORD_MAX_AGE,
            Mpadmin2fa\Security\Policy::CONFIG_AUDIT_DAYS,
            Mpadmin2fa\Security\Policy::CONFIG_APPROVAL_PROFILES,
            Mpadmin2fa\Security\Policy::CONFIG_SECURITY_RECIPIENTS,
        ] as $key) {
            $cleaned = Configuration::deleteByName($key) && $cleaned;
        }

        return $cleaned && parent::uninstall();
    }

    public function getContent(): string
    {
        Tools::redirectAdmin($this->get('router')->generate('mpadmin2fa_settings'));

        return '';
    }
}
