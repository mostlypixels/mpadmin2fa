<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Action\Row;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\AbstractRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\AccessibilityChecker\AccessibilityCheckerInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A POST row action whose CSRF token is submitted in the request body.
 */
final class SecureSubmitRowAction extends AbstractRowAction
{
    public function getType(): string
    {
        return 'mp2fa_secure_submit';
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver
            ->setRequired([
                'route',
                'route_param_name',
                'route_param_field',
                'csrf_token_field',
            ])
            ->setDefaults([
                'method' => 'POST',
                'confirm_message' => '',
                'accessibility_checker' => null,
            ])
            ->setAllowedTypes('route', 'string')
            ->setAllowedTypes('route_param_name', 'string')
            ->setAllowedTypes('route_param_field', 'string')
            ->setAllowedTypes('csrf_token_field', 'string')
            ->setAllowedTypes('method', 'string')
            ->setAllowedTypes('confirm_message', 'string')
            ->setAllowedTypes('accessibility_checker', [AccessibilityCheckerInterface::class, 'callable', 'null']);
    }

    public function isApplicable(array $record): bool
    {
        $accessibilityChecker = $this->getOptions()['accessibility_checker'];

        if ($accessibilityChecker instanceof AccessibilityCheckerInterface) {
            return $accessibilityChecker->isGranted($record);
        }

        if (is_callable($accessibilityChecker)) {
            return (bool) call_user_func($accessibilityChecker, $record);
        }

        return parent::isApplicable($record);
    }
}
