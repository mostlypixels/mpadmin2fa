<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Grid\Definition\Factory\AuditEventGridDefinitionFactory;
use Mpadmin2fa\Grid\Definition\Factory\EmployeeFactorGridDefinitionFactory;
use Mpadmin2fa\Grid\Definition\Factory\PendingApprovalGridDefinitionFactory;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatorInterface;

final class GridDefinitionFactoryTest extends TestCase
{
    public function testEmployeeFactorDefinitionCanBeBuilt(): void
    {
        $factory = new EmployeeFactorGridDefinitionFactory(
            $this->createMock(HookDispatcherInterface::class),
            $this->createMock(AuthorizationCheckerInterface::class)
        );
        $this->prepareFactory($factory);

        $definition = $factory->getDefinition();

        self::assertSame(EmployeeFactorGridDefinitionFactory::GRID_ID, $definition->getId());
        self::assertCount(7, $definition->getColumns());

        $statusColumn = array_values(array_filter(
            $definition->getColumns()->toArray(),
            static function (array $column): bool { return 'status' === $column['id']; }
        ))[0];
        self::assertSame('status_label', $statusColumn['options']['field']);
    }

    public function testPendingApprovalDefinitionCanBeBuilt(): void
    {
        $factory = new PendingApprovalGridDefinitionFactory(
            $this->createMock(HookDispatcherInterface::class),
            $this->createMock(AuthorizationCheckerInterface::class)
        );
        $this->prepareFactory($factory);

        $definition = $factory->getDefinition();

        self::assertSame(PendingApprovalGridDefinitionFactory::GRID_ID, $definition->getId());
        self::assertCount(5, $definition->getColumns());
    }

    public function testAuditEventDefinitionCanBeBuilt(): void
    {
        $factory = new AuditEventGridDefinitionFactory(
            $this->createMock(HookDispatcherInterface::class)
        );
        $this->prepareFactory($factory);

        $definition = $factory->getDefinition();

        self::assertSame(AuditEventGridDefinitionFactory::GRID_ID, $definition->getId());
        self::assertCount(5, $definition->getColumns());

        $eventColumn = array_values(array_filter(
            $definition->getColumns()->toArray(),
            static function (array $column): bool { return 'event' === $column['id']; }
        ))[0];
        self::assertSame('What happened', $eventColumn['name']);
        self::assertSame('event_label', $eventColumn['options']['field']);
    }

    private function prepareFactory(AbstractGridDefinitionFactory $factory): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static function (string $message): string { return $message; });
        $factory->setTranslator($translator);
    }
}
