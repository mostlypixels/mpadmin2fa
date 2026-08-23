<?php

declare(strict_types=1);

namespace Mpadmin2fa\Mail;

use PrestaShop\PrestaShop\Core\MailTemplate\Layout\Layout;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCollectionInterface;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeInterface;

final class MailThemeLayoutRegistrar
{
    private const SUPPORTED_THEMES = ['classic', 'modern'];

    public function register(ThemeCollectionInterface $themes, string $moduleName): void
    {
        /** @var ThemeInterface $theme */
        foreach ($themes as $theme) {
            if (!in_array($theme->getName(), self::SUPPORTED_THEMES, true)) {
                continue;
            }

            $layout = new Layout(
                'mpadmin2fa_alert',
                '@Modules/' . $moduleName . '/mails/layouts/mpadmin2fa_alert_'
                    . $theme->getName() . '.html.twig',
                '@Modules/' . $moduleName . '/mails/layouts/mpadmin2fa_alert.txt.twig',
                $moduleName
            );
            $layouts = $theme->getLayouts();
            $existing = $layouts->getLayout($layout->getName(), $layout->getModuleName());
            if (null === $existing) {
                $layouts->add($layout);

                continue;
            }

            $layouts->replace($existing, $layout);
        }
    }
}
