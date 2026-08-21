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
        $this->version = '0.2.0';
        $this->author = 'Cindy Durand';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $tabNames = [];
        foreach (Language::getLanguages(true) as $language) {
            $locale = (string) $language['locale'];
            $tabNames['parent'][$locale] = $this->trans('Admin 2FA', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['authenticator'][$locale] = $this->trans('Your authenticator', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['enrollment'][$locale] = $this->trans('Enrollment', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['security'][$locale] = $this->trans('Security', [], 'Modules.Mpadmin2fa.Admin', $locale);
        }
        $this->tabs = [
            [
                'route_name' => 'mpadmin2fa_settings',
                'class_name' => 'AdminMpAdmin2fa',
                'visible' => true,
                'name' => $tabNames['parent'],
                'icon' => 'security',
                'wording' => 'Admin 2FA',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_authenticator',
                'class_name' => 'AdminMpAdmin2faAuthenticator',
                'visible' => true,
                'name' => $tabNames['authenticator'],
                'parent_class_name' => 'AdminMpAdmin2fa',
                'wording' => 'Your authenticator',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_enrollment_employees',
                'class_name' => 'AdminMpAdmin2faEnrollment',
                'visible' => true,
                'name' => $tabNames['enrollment'],
                'parent_class_name' => 'AdminMpAdmin2fa',
                'wording' => 'Enrollment',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_security_policy',
                'class_name' => 'AdminMpAdmin2faSecurity',
                'visible' => true,
                'name' => $tabNames['security'],
                'parent_class_name' => 'AdminMpAdmin2fa',
                'wording' => 'Security',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
        ];

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
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
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

    public function postInstall(): bool
    {
        return $this->grantDefaultTabAccess();
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

    /**
     * Reconcile the visible tab hierarchy when upgrading an existing installation.
     */
    public function upgradeAdminTabs(): bool
    {
        foreach ($this->tabs as $definition) {
            $tabId = (int) Tab::getIdFromClassName($definition['class_name']);
            $tab = $tabId > 0 ? new Tab($tabId) : new Tab();
            $tab->active = (bool) $definition['visible'];
            $tab->enabled = true;
            $tab->class_name = $definition['class_name'];
            $tab->route_name = $definition['route_name'];
            $tab->module = $this->name;
            $tab->icon = $definition['icon'] ?? null;
            $tab->wording = $definition['wording'];
            $tab->wording_domain = $definition['wording_domain'];
            $tab->id_parent = isset($definition['parent_class_name'])
                ? (int) Tab::getIdFromClassName($definition['parent_class_name'])
                : 0;

            $localizedNames = $definition['name'];
            $fallbackName = reset($localizedNames);
            foreach (Language::getLanguages(false) as $language) {
                $tab->name[(int) $language['id_lang']] = $localizedNames[$language['locale']] ?? $fallbackName;
            }

            if (!($tabId > 0 ? $tab->save() : $tab->add())) {
                return false;
            }
        }

        return $this->grantDefaultTabAccess();
    }

    public function getContent(): string
    {
        Tools::redirectAdmin($this->get('router')->generate('mpadmin2fa_settings'));

        return '';
    }

    private function grantDefaultTabAccess(): bool
    {
        $tabIds = [
            (int) Tab::getIdFromClassName('AdminMpAdmin2fa'),
            (int) Tab::getIdFromClassName('AdminMpAdmin2faAuthenticator'),
        ];
        if (in_array(0, $tabIds, true)) {
            return false;
        }

        $access = new Access();
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach (Profile::getProfiles($languageId) as $profile) {
            foreach ($tabIds as $tabId) {
                if ('ok' !== $access->updateLgcAccess((int) $profile['id_profile'], $tabId, 'view', true, false)) {
                    return false;
                }
            }
        }

        return true;
    }
}
