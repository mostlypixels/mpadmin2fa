<?php

declare(strict_types=1);

namespace Mpadmin2fa\Install;

use Access;
use Configuration;
use Db;
use Language;
use Profile;
use Tab;
use Throwable;

final class AdminTabLifecycle
{
    public function reconcile(string $moduleName, array $declaredTabs): bool
    {
        $definitions = (new AdminTabHierarchy())->buildUpgradeDefinitions($declaredTabs);

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
            $tab->module = $moduleName;
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

        return $this->grantDefaultAccess();
    }

    public function remove(string $moduleName, array $declaredTabs): bool
    {
        $tabIds = [];
        try {
            $definitions = (new AdminTabHierarchy())->buildUpgradeDefinitions($declaredTabs);
            foreach (array_reverse($definitions) as $definition) {
                $tabId = (int) Tab::getIdFromClassName($definition['class_name']);
                if ($tabId > 0) {
                    $tabIds[] = $tabId;
                }
            }

            $rows = Db::getInstance()->executeS(
                'SELECT id_tab FROM ' . _DB_PREFIX_ . 'tab WHERE module = "' . pSQL($moduleName) . '"',
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $tabIds[] = (int) $row['id_tab'];
            }
        } catch (Throwable) {
            return false;
        }

        $cleaned = true;
        foreach (array_values(array_unique($tabIds)) as $tabId) {
            $tab = new Tab($tabId);
            if ((int) $tab->id > 0) {
                $cleaned = $this->removeAccessForTab($tab) && $cleaned;
                $cleaned = $tab->delete() && $cleaned;
            }
        }

        return $cleaned;
    }

    private function removeAccessForTab(Tab $tab): bool
    {
        $slugPrefix = 'ROLE_MOD_TAB_' . strtoupper((string) $tab->class_name) . '_';
        $roles = Db::getInstance()->executeS(
            'SELECT id_authorization_role FROM ' . _DB_PREFIX_ . 'authorization_role'
            . ' WHERE slug IN ('
            . '"' . pSQL($slugPrefix . 'CREATE') . '",'
            . '"' . pSQL($slugPrefix . 'DELETE') . '",'
            . '"' . pSQL($slugPrefix . 'READ') . '",'
            . '"' . pSQL($slugPrefix . 'UPDATE') . '"'
            . ')',
        );

        $cleaned = true;
        foreach (is_array($roles) ? $roles : [] as $role) {
            $cleaned = Db::getInstance()->delete(
                'access',
                'id_authorization_role = ' . (int) $role['id_authorization_role'],
            ) && $cleaned;
        }

        return $cleaned;
    }

    private function grantDefaultAccess(): bool
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
