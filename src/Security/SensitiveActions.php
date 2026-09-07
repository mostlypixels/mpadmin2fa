<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class SensitiveActions
{
    private const WORDS = [
        'bulk', 'configure', 'delete', 'disable', 'enable', 'import',
        'install', 'reset', 'submit', 'uninstall', 'update', 'upgrade',
    ];
    private const VALUE_KEYS = ['action', 'submitAction', 'bulk_action', 'module_action'];

    public static function contains(string $action): bool
    {
        $action = self::normalize($action);
        foreach (self::WORDS as $word) {
            if (false !== strpos($action, $word)) {
                return true;
            }
        }

        return false;
    }

    public static function fromParameters(array $parameters): string
    {
        $actions = [];
        foreach (self::VALUE_KEYS as $key) {
            if (array_key_exists($key, $parameters)) {
                // Malformed action values must not turn a protected operation into a read.
                $actions[] = is_scalar($parameters[$key]) ? self::normalize((string) $parameters[$key]) : 'submit';
            }
        }
        foreach (array_keys($parameters) as $key) {
            $action = self::normalize((string) $key);
            if (self::contains($action)) {
                if (0 === strpos($action, 'submit') && self::contains(substr($action, 6))) {
                    $action = substr($action, 6);
                }
                $actions[] = $action;
            }
        }

        return implode(' ', array_unique(array_filter($actions)));
    }

    private static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }
}
