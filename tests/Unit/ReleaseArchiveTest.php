<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2faBuild\ReleaseArchive;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/tools/ReleaseArchive.php';

final class ReleaseArchiveTest extends TestCase
{
    private $path;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Release archive tests require the zip extension.');
        }
        $this->path = tempnam(sys_get_temp_dir(), 'mp2fa-zip-');
    }

    protected function tearDown(): void
    {
        if ($this->path && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAllowsOnlyTheRequiredPrestaShopBridge(): void
    {
        $this->archive([]);
        ReleaseArchive::verify($this->path);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider forbiddenEntries */
    public function testRejectsUnscopedDependenciesAndDevelopmentFiles(string $entry): void
    {
        $this->archive([$entry => 'unexpected']);
        $this->expectException(RuntimeException::class);
        ReleaseArchive::verify($this->path);
    }

    public function forbiddenEntries(): array
    {
        return array_map(static function (string $entry): array {
            return [$entry];
        }, [
            'mpadmin2fa/vendor/composer/autoload_real.php',
            'mpadmin2fa/config.xml',
            'mpadmin2fa/tests/example.php',
            'mpadmin2fa/tools/release.php',
            'mpadmin2fa/prestashop/index.php',
            'mpadmin2fa/node_modules/package.json',
            'mpadmin2fa/.phpunit.result.cache',
            'mpadmin2fa/AGENTS.md',
            'mpadmin2fa/vendor-scoped/example/package/.github/workflows/tests.yml',
            'mpadmin2fa/vendor-scoped/example/package/tests/example.php',
            'mpadmin2fa/vendor-scoped/example/package/docs/readme.md',
            'mpadmin2fa/vendor-scoped/example/package/composer.json',
            'mpadmin2fa/vendor-scoped/example/package/phpunit.xml.dist',
            'mpadmin2fa/../outside.php',
            'outside.php',
        ]);
    }

    public function testRejectsAModifiedBridge(): void
    {
        $this->archive(['mpadmin2fa/vendor/autoload.php' => '<?php require "unscoped.php";']);
        $this->expectException(RuntimeException::class);
        ReleaseArchive::verify($this->path);
    }

    public function testRequiresTheBridge(): void
    {
        $this->archive([]);
        $zip = new ZipArchive();
        $zip->open($this->path);
        $zip->deleteName('mpadmin2fa/vendor/autoload.php');
        $zip->close();
        $this->expectException(RuntimeException::class);
        ReleaseArchive::verify($this->path);
    }

    private function archive(array $extra): void
    {
        $entries = [
            'mpadmin2fa/mpadmin2fa.php' => '<?php',
            'mpadmin2fa/vendor/autoload.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn require dirname(__DIR__) . '/vendor-scoped/autoload.php';\n",
            'mpadmin2fa/vendor-scoped/autoload.php' => '<?php',
            'mpadmin2fa/SBOM.json' => '{}',
            'mpadmin2fa/SHA256SUMS' => '',
        ];
        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (array_merge($entries, $extra) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }
}

