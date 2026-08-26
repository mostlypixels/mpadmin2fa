<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Mail\MailThemeLayoutRegistrar;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\MailTemplate\Theme;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCollection;

final class MailThemeLayoutRegistrarTest extends TestCase
{
    public function testRegistersOneRegenerableLayoutInEachBuiltInTheme(): void
    {
        $classic = new Theme('classic');
        $modern = new Theme('modern');
        $custom = new Theme('custom');
        $themes = new ThemeCollection([$classic, $modern, $custom]);
        $registrar = new MailThemeLayoutRegistrar();

        $registrar->register($themes, 'mpadmin2fa');
        $registrar->register($themes, 'mpadmin2fa');

        $classicLayout = $classic->getLayouts()->getLayout('mpadmin2fa_alert', 'mpadmin2fa');
        self::assertNotNull($classicLayout);
        self::assertSame(
            '@Modules/mpadmin2fa/mails/layouts/mpadmin2fa_alert_classic.html.twig',
            $classicLayout->getHtmlPath()
        );
        self::assertSame(
            '@Modules/mpadmin2fa/mails/layouts/mpadmin2fa_alert.txt.twig',
            $classicLayout->getTxtPath()
        );
        self::assertCount(1, $classic->getLayouts());

        $modernLayout = $modern->getLayouts()->getLayout('mpadmin2fa_alert', 'mpadmin2fa');
        self::assertNotNull($modernLayout);
        self::assertSame(
            '@Modules/mpadmin2fa/mails/layouts/mpadmin2fa_alert_modern.html.twig',
            $modernLayout->getHtmlPath()
        );
        self::assertSame(
            '@Modules/mpadmin2fa/mails/layouts/mpadmin2fa_alert.txt.twig',
            $modernLayout->getTxtPath()
        );
        self::assertCount(1, $modern->getLayouts());
        self::assertCount(0, $custom->getLayouts());
    }
}
