<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$phpunit = $moduleRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
$arguments = array_slice($argv, 1);
$testPaths = [];
$escaped = [];

if ('1' === getenv('MP2FA_STANDALONE_TESTS') || !is_file(dirname($moduleRoot, 2) . '/vendor/autoload.php')) {
    foreach (glob($moduleRoot . '/tests/Unit/*Test.php') as $testFile) {
        $source = (string) file_get_contents($testFile);
        if (false === strpos($source, "dirname(__DIR__, 4) . '/vendor/autoload.php'")) {
            $testPaths[] = $testFile;
        }
    }

    fwrite(STDOUT, "PrestaShop checkout not found; running the standalone-compatible unit tests.\n");
    fwrite(STDOUT, "Place the module at modules/mpadmin2fa in a PrestaShop checkout for the complete suite.\n");
}

$command = [
    PHP_BINARY,
    $phpunit,
    '-c',
    $moduleRoot . DIRECTORY_SEPARATOR . 'phpunit.xml.dist',
    '--bootstrap',
    $moduleRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
];
foreach (array_merge($command, $arguments, $testPaths) as $part) {
    $escaped[] = escapeshellarg($part);
}

passthru(implode(' ', $escaped), $status);
exit($status);
