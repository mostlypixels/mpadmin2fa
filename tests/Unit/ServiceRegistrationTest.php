<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Controller\Admin\MfaController;
use Mpadmin2fa\EventSubscriber\AdminMfaSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ServiceRegistrationTest extends TestCase
{
    private function loadServicesAfterClassResolution(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // Reproduce PS 8.0.0: class resolution has already run when modules load.
        (new ResolveClassPass())->process($container);
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(dirname(__DIR__, 2) . '/config/admin')
        );
        $loader->load('services.yml');

        return $container;
    }

    public function testConfigurationDependenciesUseTheNativeServiceOnEarlyPs8(): void
    {
        $container = $this->loadServicesAfterClassResolution();
        foreach ([
            'Mpadmin2fa\Configuration\SecurityPolicyConfiguration',
            'Mpadmin2fa\Security\Policy',
            'Mpadmin2fa\Security\SecurityAlertService',
        ] as $service) {
            self::assertSame(
                'prestashop.adapter.legacy.configuration',
                (string) $container->getDefinition($service)->getArgument('$configuration')
            );
        }
    }

    public function testModuleServicesDoNotDependOnEarlierCompilerPasses(): void
    {
        $container = $this->loadServicesAfterClassResolution();
        $count = 0;
        foreach ($container->getDefinitions() as $id => $definition) {
            if (0 !== strpos($id, 'Mpadmin2fa\\')) {
                continue;
            }
            ++$count;
            self::assertSame($id, $definition->getClass(), $id);
            self::assertFalse($definition->isAutoconfigured(), $id);
        }
        self::assertGreaterThan(30, $count);
    }

    public function testSecuritySubscriberAndControllerAreExplicitlyRegistered(): void
    {
        $container = $this->loadServicesAfterClassResolution();
        $subscriber = $container->getDefinition(AdminMfaSubscriber::class);
        self::assertTrue($subscriber->hasTag('kernel.event_subscriber'));

        $controller = $container->getDefinition(MfaController::class);
        self::assertTrue($controller->hasTag('controller.service_arguments'));
        self::assertTrue($controller->isPublic());
        self::assertTrue($controller->hasMethodCall('setContainer'));
    }

    public function testFormsAndCommandsAreExplicitlyTagged(): void
    {
        $container = $this->loadServicesAfterClassResolution();
        foreach (['KeyHealthCommand', 'KeyRotationCommand', 'PruneAuditCommand', 'ResetFactorCommand'] as $command) {
            self::assertTrue($container->getDefinition('Mpadmin2fa\\Command\\' . $command)->hasTag('console.command'));
        }
        foreach ([
            'DisableFactorType',
            'FactorConfirmationType',
            'OneTimeCodeType',
            'RecoveryCodeAcknowledgementType',
            'RecoveryCodeChallengeType',
            'RegenerateRecoveryCodesType',
            'ReplaceFactorType',
            'SecurityPolicyType',
        ] as $form) {
            self::assertTrue($container->getDefinition('Mpadmin2fa\\Form\\' . $form)->hasTag('form.type'));
        }
    }
}
