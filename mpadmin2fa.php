<?php

declare(strict_types=1);

use Mpadmin2fa\Http\LegacyAdminMfaAdapter;
use Mpadmin2fa\Mail\MailThemeLayoutRegistrar;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\DashboardActivityWindow;
use Mpadmin2fa\Security\SecurityActivityAccess;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCatalogInterface;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCollectionInterface;

/*
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
        $this->version = '0.2.8';
        $this->author = 'A vibe coder';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $tabNames = [];
        foreach (Language::getLanguages(true) as $language) {
            $locale = (string) $language['locale'];
            $tabNames['parent'][$locale] = $this->trans('Admin 2FA', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['authenticator'][$locale] = $this->trans('Your authenticator', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['enrollment'][$locale] = $this->trans('Employee 2FA', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['security'][$locale] = $this->trans('Security', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['activity'][$locale] = $this->trans('Activity log', [], 'Modules.Mpadmin2fa.Admin', $locale);
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
                'wording' => 'Employee 2FA',
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
            [
                'route_name' => 'mpadmin2fa_security_activity',
                'class_name' => 'AdminMpAdmin2faSecurityActivity',
                'visible' => true,
                'name' => $tabNames['activity'],
                'parent_class_name' => 'AdminMpAdmin2faSecurity',
                'wording' => 'Activity log',
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
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.2.99'];
    }

    public function install(): bool
    {
        if (!is_file(__DIR__ . '/vendor/autoload.php') && !is_file(__DIR__ . '/vendor-scoped/autoload.php')) {
            $this->_errors[] = $this->trans('The production dependencies are missing.', [], 'Modules.Mpadmin2fa.Admin');

            return false;
        }

        try {
            $installed = parent::install()
                && $this->registerHook('actionDispatcherBefore')
                && $this->registerHook('actionAdminControllerSetMedia')
                && $this->registerHook('actionObjectProfileDeleteAfter')
                && $this->registerHook('dashboardZoneOne')
                && $this->registerHook('displayAdminDashboardZoneOne')
                && $this->registerHook(ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK)
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
            try {
                if (!(new Mpadmin2fa\Install\SchemaInstaller())->uninstall()) {
                    $this->_errors[] = $this->trans(
                        'The failed installation could not remove every Admin 2FA database table.',
                        [],
                        'Modules.Mpadmin2fa.Admin'
                    );
                }
            } catch (Throwable $cleanupException) {
                $this->_errors[] = $cleanupException->getMessage();
            }
            if (!parent::uninstall()) {
                $this->_errors[] = $this->trans(
                    'The failed installation could not remove every native module record.',
                    [],
                    'Modules.Mpadmin2fa.Admin'
                );
            }
        }

        return $installed;
    }

    public function postInstall(): bool
    {
        return $this->reconcileAdminTabs();
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

        $moduleCleaned = parent::uninstall();

        return $cleaned && $moduleCleaned;
    }

    /**
     * Reconcile the visible tab hierarchy when upgrading an existing installation.
     */
    public function upgradeAdminTabs(): bool
    {
        return $this->reconcileAdminTabs();
    }

    /**
     * Idempotently repair the native PS 8 menu hierarchy and default access.
     */
    public function reconcileAdminTabs(): bool
    {
        $definitions = (new Mpadmin2fa\Install\AdminTabHierarchy())->buildUpgradeDefinitions($this->tabs);

        foreach ($definitions as $definition) {
            $parentId = (int) Tab::getIdFromClassName($definition['parent_class_name']);
            if ($parentId <= 0) {
                return false;
            }

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
            $tab->id_parent = $parentId;

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

    /**
     * Load the listener that turns an AJAX step-up response into a full-page challenge.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionAdminControllerSetMedia(array $params): void
    {
        if (!method_exists($this->context->controller, 'addJS')) {
            return;
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin-step-up.js');
    }

    /**
     * Stop legacy back-office requests before their controller is instantiated.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionDispatcherBefore(array $params): void
    {
        $response = $this->get(LegacyAdminMfaAdapter::class)->enforce(
            $this->context,
            (int) ($params['controller_type'] ?? -1)
        );
        if (null === $response) {
            return;
        }

        $response->send();
        exit;
    }

    /**
     * Remove deleted profile IDs from the 2FA policy settings.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionObjectProfileDeleteAfter(array $params): void
    {
        $profile = $params['object'] ?? null;
        if (!$profile instanceof Profile || (int) $profile->id <= 0) {
            return;
        }

        foreach ([
            Mpadmin2fa\Security\Policy::CONFIG_PROFILES,
            Mpadmin2fa\Security\Policy::CONFIG_APPROVAL_PROFILES,
        ] as $configurationKey) {
            $currentValue = (string) Configuration::get($configurationKey);
            $cleanedValue = Mpadmin2fa\Security\ProfilePolicyCleaner::removeFromList(
                $currentValue,
                (int) $profile->id
            );
            if ($cleanedValue !== $currentValue) {
                Configuration::updateValue($configurationKey, $cleanedValue);
            }
        }
    }

    /**
     * Add the Admin 2FA alert layout to PrestaShop's built-in mail themes.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionListMailThemes(array $params): void
    {
        $themes = $params['mailThemes'] ?? null;
        if (!$themes instanceof ThemeCollectionInterface) {
            return;
        }

        (new MailThemeLayoutRegistrar())->register($themes, $this->name);
    }

    /**
     * Display the enrollment summary on the migrated dashboard.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminDashboardZoneOne(array $params): string
    {
        $twig = $this->getTwig();
        if (null === $twig) {
            return '';
        }

        return $twig->render(
            '@Modules/mpadmin2fa/views/templates/hook/dashboard_enrollment.html.twig',
            $this->dashboardEnrollmentTemplateData()
        );
    }

    /**
     * Display the enrollment summary on the legacy dashboard.
     *
     * @param array<string, mixed> $params
     */
    public function hookDashboardZoneOne(array $params): string
    {
        $this->context->smarty->assign($this->dashboardEnrollmentTemplateData());

        return $this->display(__FILE__, 'dashboard_enrollment.tpl');
    }

    /**
     * @return array{
     *     title: string,
     *     message_prefix: string,
     *     enrollment_link_label: string,
     *     enrollment_url: string,
     *     has_unenrolled: bool,
     *     can_view_security_activity: bool,
     *     security_activity_title: string,
     *     security_activity_empty: string,
     *     security_activity_see_all: string,
     *     security_activity_url: string,
     *     security_event_column_label: string,
     *     security_occurrences_column_label: string,
     *     security_window_hours: int,
     *     security_events: list<array{
     *         date_add: string,
     *         employee: string,
     *         event_label: string,
     *         occurrences: int,
     *         occurrence_label: string
     *     }>
     * }
     */
    private function dashboardEnrollmentTemplateData(): array
    {
        $repository = $this->get(SecurityRepository::class);
        $summary = [
            'not_enrolled' => 0,
            'total' => 0,
        ];
        if ($repository instanceof SecurityRepository) {
            $summary = $repository->activeEmployeeEnrollmentSummary();
        }

        $access = $this->get(SecurityActivityAccess::class);
        $canViewSecurityActivity = $access instanceof SecurityActivityAccess && $access->canRead();
        $windowHours = 48;
        $securityEvents = [];
        if ($canViewSecurityActivity && $repository instanceof SecurityRepository) {
            $timezoneName = (string) Configuration::get('PS_TIMEZONE');
            try {
                $timezone = new DateTimeZone('' !== $timezoneName ? $timezoneName : 'UTC');
            } catch (Throwable $exception) {
                $timezone = new DateTimeZone('UTC');
            }
            $now = new DateTimeImmutable('now', $timezone);
            $window = new DashboardActivityWindow();
            $windowHours = $window->hours($now);
            $securityEvents = $repository->importantDashboardEventsSince($window->since($now));
            foreach ($securityEvents as &$event) {
                $event['occurrence_label'] = $event['occurrences'] > 1
                    ? $this->trans(
                        '%count% times',
                        ['%count%' => (string) $event['occurrences']],
                        'Modules.Mpadmin2fa.Admin'
                    )
                    : '';
            }
            unset($event);
        }

        return [
            'title' => $this->trans('Two-factor authentication', [], 'Modules.Mpadmin2fa.Admin'),
            'message_prefix' => $this->trans(
                '%not_enrolled% out of your %total% employees are not enrolled in the',
                [
                    '%not_enrolled%' => (string) $summary['not_enrolled'],
                    '%total%' => (string) $summary['total'],
                ],
                'Modules.Mpadmin2fa.Admin'
            ),
            'enrollment_link_label' => $this->trans(
                'two factor system',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'enrollment_url' => $this->context->link->getAdminLink('AdminMpAdmin2faEnrollment'),
            'has_unenrolled' => $summary['not_enrolled'] > 0,
            'can_view_security_activity' => $canViewSecurityActivity,
            'security_activity_title' => $this->trans(
                'Important security activity from the last %hours% hours',
                ['%hours%' => (string) $windowHours],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_activity_empty' => $this->trans(
                'No important security events occurred during this period.',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_activity_see_all' => $this->trans(
                'See all',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_activity_url' => $this->get('router')->generate('mpadmin2fa_security_activity'),
            'security_event_column_label' => $this->trans(
                'Security event',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_occurrences_column_label' => $this->trans(
                'Occurrences',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_window_hours' => $windowHours,
            'security_events' => $securityEvents,
        ];
    }

    private function grantDefaultTabAccess(): bool
    {
        $tabIds = [
            (int) Tab::getIdFromClassName('AdminMpAdmin2fa_MTR'),
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
