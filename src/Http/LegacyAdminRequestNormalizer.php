<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Mpadmin2fa\Security\SensitiveActions;

final class LegacyAdminRequestNormalizer
{
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
        return SensitiveActions::fromParameters($parameters);
    }
}
