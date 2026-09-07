<?php

declare(strict_types=1);

namespace Mpadmin2faBuild;

use RuntimeException;
use ZipArchive;

final class ReleaseArchive
{
    private const ROOT_FORBIDDEN = [
        '.ai',
        '.agents',
        '.claude',
        '.codex',
        '.git',
        '.github',
        '.gitignore',
        '.phpunit.cache',
        '.phpunit.result.cache',
        'AGENTS.md',
        'build',
        'config.xml',
        'dist',
        'docs',
        'documentation',
        'node_modules',
        'NUL',
        'php-scoper.inc.php',
        'phpunit.xml.dist',
        'prestashop',
        'tests',
        'tools',
    ];

    public static function verify(string $path): void
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($path)) {
            throw new RuntimeException('Unable to open the release archive.');
        }
        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = $zip->getNameIndex($index);
                if (false === $entry || 0 !== strpos($entry, 'mpadmin2fa/')
                    || false !== strpos($entry, '\\') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $entry)
                    || isset($entries[$entry])
                ) {
                    throw new RuntimeException('Invalid or duplicate archive path.');
                }
                $relative = substr($entry, strlen('mpadmin2fa/'));
                self::rejectDevelopmentPath($entry, $relative);
                if (0 === strpos($relative, 'vendor/') && !in_array($relative, ['vendor/', 'vendor/autoload.php'], true)) {
                    throw new RuntimeException('Unscoped dependency found: ' . $entry);
                }
                $entries[$entry] = true;
            }
            foreach (['mpadmin2fa.php', 'vendor/autoload.php', 'vendor-scoped/autoload.php', 'SBOM.json', 'SHA256SUMS'] as $required) {
                if (!isset($entries['mpadmin2fa/' . $required])) {
                    throw new RuntimeException('Required release file missing: ' . $required);
                }
            }
            // Only this dependency-free bridge is permitted under vendor/.
            $bridge = "<?php\n\ndeclare(strict_types=1);\n\nreturn require dirname(__DIR__) . '/vendor-scoped/autoload.php';\n";
            if ($bridge !== $zip->getFromName('mpadmin2fa/vendor/autoload.php')) {
                throw new RuntimeException('The PrestaShop autoload bridge has unexpected contents.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function rejectDevelopmentPath(string $entry, string $relative): void
    {
        foreach (self::ROOT_FORBIDDEN as $forbidden) {
            if ($relative === $forbidden || 0 === strpos($relative, $forbidden . '/')) {
                throw new RuntimeException('Development-only path found: ' . $entry);
            }
        }

        if (preg_match('~(?:^|/)(?:\.git|\.github|tests?|docs?|documentation|examples?|node_modules)(?:/|$)~i', $relative)
            || preg_match('~(?:^|/)(?:\.editorconfig|\.gitattributes|\.gitignore|composer\.lock|phpunit(?:\.xml(?:\.dist)?)?|phpstan\.neon(?:\.dist)?|psalm\.xml)$~i', $relative)
            || preg_match('~^vendor-scoped/[^/]+/[^/]+/composer\.json$~', $relative)
        ) {
            throw new RuntimeException('Nested development metadata found: ' . $entry);
        }
    }
}
