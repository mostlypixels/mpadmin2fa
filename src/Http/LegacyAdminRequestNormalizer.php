<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

final class LegacyAdminRequestNormalizer
{
    private const ACTION_KEYS = [
        'action',
        'bulk_action',
        'module_action',
    ];

    public function controller($value): string
    {
        $controller = preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
        if (null === $controller || '' === $controller) {
            return '';
        }

        $controller = 0 === strcasecmp('Controller', substr($controller, -10)) ? substr($controller, 0, -10) : $controller;
        foreach (['AdminLogin', 'AdminModules', 'AdminThemes', 'AdminImport'] as $canonical) {
            if (0 === strcasecmp($controller, $canonical)) {
                return $canonical;
            }
        }

        return $controller;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function action(array $parameters): string
    {
        $actions = [];
        foreach (self::ACTION_KEYS as $key) {
            if (isset($parameters[$key]) && is_scalar($parameters[$key])) {
                $action = $this->normalize((string) $parameters[$key]);
                if ('' !== $action) {
                    $actions[] = $action;
                }
            }
        }

        foreach (array_keys($parameters) as $key) {
            $normalized = $this->normalize((string) $key);
            if (0 === strpos($normalized, 'submit')) {
                $normalized = substr($normalized, 6);
            }
            if (preg_match('/(?:bulk|configure|delete|disable|enable|import|install|reset|uninstall|update|upgrade)/', $normalized)) {
                $actions[] = $normalized;
            }
        }

        return implode(' ', array_unique($actions));
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
