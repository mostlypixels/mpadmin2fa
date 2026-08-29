<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Http\LegacyAdminRequestNormalizer;
use PHPUnit\Framework\TestCase;

final class LegacyAdminRequestNormalizerTest extends TestCase
{
    public function testControllerIsNormalizedWithoutTrustingArbitraryCharacters(): void
    {
        $normalizer = new LegacyAdminRequestNormalizer();

        self::assertSame('AdminModules', $normalizer->controller('AdminModulesController'));
        self::assertSame('AdminModules', $normalizer->controller('Admin<Modules'));
    }

    /**
     * @dataProvider actionParameters
     *
     * @param array<string, mixed> $parameters
     */
    public function testSensitiveLegacyActionsAreNormalized(array $parameters, string $expected): void
    {
        self::assertSame($expected, (new LegacyAdminRequestNormalizer())->action($parameters));
    }

    public static function actionParameters(): iterable
    {
        yield 'explicit action' => [['action' => 'install'], 'install'];
        yield 'bulk submit' => [['submitBulkdisablemodule' => '1'], 'bulkdisablemodule'];
        yield 'upgrade flag' => [['upgrade' => 'example'], 'upgrade'];
        yield 'uninstall flag' => [['uninstall' => 'example'], 'uninstall'];
    }
}
