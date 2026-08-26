<?php

declare(strict_types=1);

namespace Mpadmin2fa\Install;

use InvalidArgumentException;

final class AdminTabHierarchy
{
    private const DEFAULT_PARENT_CLASS = 'DEFAULT';
    private const MENU_WRAPPER_SUFFIX = '_MTR';

    /**
     * Build the hierarchy produced by PrestaShop's ModuleTabRegister for
     * clickable module tabs that also own child tabs.
     *
     * @param array<int, array<string, mixed>> $definitions
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildUpgradeDefinitions(array $definitions): array
    {
        if ([] === $definitions || empty($definitions[0]['class_name'])) {
            throw new InvalidArgumentException('The parent admin tab definition is missing.');
        }

        $rootClassName = (string) $definitions[0]['class_name'];
        $parentClasses = [$rootClassName => true];
        foreach ($definitions as $definition) {
            if (!empty($definition['parent_class_name'])) {
                $parentClasses[(string) $definition['parent_class_name']] = true;
            }
        }

        $upgradeDefinitions = [];
        foreach ($definitions as $definition) {
            $className = (string) $definition['class_name'];
            $parentClassName = (string) ($definition['parent_class_name'] ?? self::DEFAULT_PARENT_CLASS);
            if (isset($parentClasses[$parentClassName])) {
                $parentClassName .= self::MENU_WRAPPER_SUFFIX;
            }

            if (isset($parentClasses[$className])) {
                $wrapper = $definition;
                $wrapper['class_name'] = $className . self::MENU_WRAPPER_SUFFIX;
                $wrapper['parent_class_name'] = $parentClassName;
                $upgradeDefinitions[] = $wrapper;

                $parentClassName = $wrapper['class_name'];
            }

            $definition['parent_class_name'] = $parentClassName;
            $upgradeDefinitions[] = $definition;
        }

        return $upgradeDefinitions;
    }
}
