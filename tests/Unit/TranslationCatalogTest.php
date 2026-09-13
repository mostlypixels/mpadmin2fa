<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\SecurityAlertCatalog;
use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase
{
    private const DOMAINS = [
        'Modules.Mpadmin2fa.Admin' => 'ModulesMpadmin2faAdmin.fr-FR.xlf',
        'Modules.Mpadmin2fa.Emails' => 'ModulesMpadmin2faEmails.fr-FR.xlf',
    ];

    public function testFrenchCatalogsTranslateEveryLiteralModuleMessage(): void
    {
        $catalogs = $this->catalogs();
        $missing = [];
        foreach ($this->sourceMessages() as $domain => $messages) {
            foreach (array_keys($messages) as $message) {
                if ('' === ($catalogs[$domain][$message] ?? '')) {
                    $missing[] = $domain . ': ' . $message;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testSecurityAlertGuidanceIsTranslatedForSettingsAndEmails(): void
    {
        $catalogs = $this->catalogs();
        $missing = [];
        foreach ((new SecurityAlertCatalog())->all() as $alert) {
            foreach (['title', 'priority', 'meaning', 'action'] as $field) {
                if ('' === ($catalogs['Modules.Mpadmin2fa.Admin'][$alert[$field]] ?? '')) {
                    $missing[] = 'Admin: ' . $alert[$field];
                }
            }
            foreach (['meaning', 'action'] as $field) {
                if ('' === ($catalogs['Modules.Mpadmin2fa.Emails'][$alert[$field]] ?? '')) {
                    $missing[] = 'Emails: ' . $alert[$field];
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testTranslationsKeepTheirPlaceholders(): void
    {
        $mismatches = [];
        foreach ($this->catalogs() as $domain => $catalog) {
            foreach ($catalog as $source => $target) {
                preg_match_all('/%\w+%/', $source, $sourcePlaceholders);
                preg_match_all('/%\w+%/', $target, $targetPlaceholders);
                sort($sourcePlaceholders[0]);
                sort($targetPlaceholders[0]);
                if ($sourcePlaceholders[0] !== $targetPlaceholders[0]) {
                    $mismatches[] = $domain . ': ' . $source;
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function catalogs(): array
    {
        $catalogs = [];
        foreach (self::DOMAINS as $domain => $file) {
            $xml = simplexml_load_file(dirname(__DIR__, 2) . '/translations/fr-FR/' . $file);
            self::assertNotFalse($xml, $file);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $catalogs[$domain] = [];
            foreach ($xml->xpath('//x:trans-unit') as $unit) {
                $children = $unit->children('urn:oasis:names:tc:xliff:document:1.2');
                $catalogs[$domain][(string) $children->source] = (string) $children->target;
            }
        }

        return $catalogs;
    }

    /**
     * Collects trans() calls and Twig |trans filters whose message and module domain are literals.
     *
     * @return array<string, array<string, true>>
     */
    private function sourceMessages(): array
    {
        $root = dirname(__DIR__, 2);
        $messages = array_fill_keys(array_keys(self::DOMAINS), []);
        $paths = [$root . '/mpadmin2fa.php'];
        foreach (['src', 'views', 'mails'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $paths[] = $file->getPathname();
            }
        }

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            if ('.php' === substr($path, -4)) {
                foreach ($this->phpMessages($source) as $message) {
                    if (isset($messages[$message[0]])) {
                        $messages[$message[0]][$message[1]] = true;
                    }
                }
            } elseif ('.twig' === substr($path, -5)) {
                preg_match_all(
                    "/(?:'((?:[^'\\\\]|\\\\.)+)'|\"([^\"]+)\")\\s*\\|\\s*trans\\(\\s*\\{[^}]*\\}\\s*,\\s*'(Modules\\.Mpadmin2fa\\.\\w+)'/",
                    $source,
                    $matches,
                    PREG_SET_ORDER
                );
                foreach ($matches as $match) {
                    $message = '' !== $match[1] ? str_replace("\\'", "'", $match[1]) : $match[2];
                    if (isset($messages[$match[3]])) {
                        $messages[$match[3]][$message] = true;
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function phpMessages(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            if (!is_array($tokens[$index]) || T_STRING !== $tokens[$index][0] || 'trans' !== $tokens[$index][1]) {
                continue;
            }

            $arguments = [[]];
            $depth = 0;
            for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
                $token = $tokens[$cursor];
                if (is_array($token) && T_WHITESPACE === $token[0]) {
                    continue;
                }
                if (0 === $depth && '(' !== $token) {
                    break;
                }
                if ('(' === $token || '[' === $token) {
                    ++$depth;
                    if (1 === $depth) {
                        continue;
                    }
                } elseif (')' === $token || ']' === $token) {
                    --$depth;
                    if (0 === $depth) {
                        break;
                    }
                } elseif (',' === $token && 1 === $depth) {
                    $arguments[] = [];
                    continue;
                }
                $arguments[count($arguments) - 1][] = $token;
            }

            $message = $this->literal($arguments[0] ?? []);
            // PrestaShop 1.7 and 8 controllers take the domain as the second argument.
            $domain = $this->literal($arguments[2] ?? []) ?? $this->literal($arguments[1] ?? []);
            if (null !== $message && null !== $domain) {
                $found[] = [$domain, $message];
            }
        }

        return $found;
    }

    private function literal(array $tokens): ?string
    {
        if (1 !== count($tokens) || !is_array($tokens[0]) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0][0]) {
            return null;
        }

        $quoted = $tokens[0][1];
        $body = substr($quoted, 1, -1);

        return "'" === $quoted[0]
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
    }
}
