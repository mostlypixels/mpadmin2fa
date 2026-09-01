<?php

declare(strict_types=1);

if (PHP_VERSION_ID >= 80500) {
    throw new RuntimeException('Build scoped releases with PHP 8.1-8.4; PHP-Scoper is not yet reliable on PHP 8.5.');
}

$moduleRoot = dirname(__DIR__);
$buildRoot = $moduleRoot . '/build';
$stageRoot = $buildRoot . '/stage';
$releaseRoot = $buildRoot . '/mpadmin2fa';

if (dirname($buildRoot) !== $moduleRoot || 'build' !== basename($buildRoot)) {
    throw new RuntimeException('Unsafe build directory.');
}

removeTree($buildRoot);
mkdir($stageRoot, 0775, true);
copyTree($moduleRoot, $stageRoot, [
    '.git',
    '.github',
    '.gitignore',
    '.phpunit.cache',
    'build',
    'dist',
    'docs',
    'documentation',
    'NUL',
    'php-scoper.inc.php',
    'phpunit.xml.dist',
    'prestashop',
    'tests',
    'tools',
    'vendor',
]);

run('composer config autoloader-suffix MpAdmin2FaScoped', $stageRoot);
run('composer install --no-dev --prefer-dist --no-interaction --no-progress', $stageRoot);

putenv('MP2FA_SCOPE_ROOT=' . $stageRoot);
run(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($moduleRoot . '/vendor/bin/php-scoper')
    . ' add-prefix --config=' . escapeshellarg($moduleRoot . '/php-scoper.inc.php')
    . ' --output-dir=' . escapeshellarg($releaseRoot)
    . ' --force --stop-on-failure --no-interaction',
    $moduleRoot
);

copyTree($stageRoot, $releaseRoot, [], false);
removeTree($releaseRoot . '/vendor/composer');
copyTree($stageRoot . '/vendor/composer', $releaseRoot . '/vendor/composer');
copy($stageRoot . '/vendor/autoload.php', $releaseRoot . '/vendor/autoload.php');
if (!rename($releaseRoot . '/vendor', $releaseRoot . '/vendor-scoped')) {
    throw new RuntimeException('Unable to rename the scoped production dependency directory.');
}
writeScopedAutoload($releaseRoot . '/vendor-scoped');
writePrestaShopAutoloadBridge($releaseRoot);

$composerAutoload = file_get_contents($releaseRoot . '/vendor-scoped/composer/autoload_real.php');
if (false === $composerAutoload || !str_contains($composerAutoload, 'ComposerAutoloaderInitMpAdmin2FaScoped')) {
    throw new RuntimeException('The scoped release Composer autoloader does not have its package-specific suffix.');
}

assertModuleNamespacesPreserved($releaseRoot);
$autoloadSmokeCode = 'require ' . var_export($moduleRoot . '/vendor/autoload.php', true)
    . '; require ' . var_export($releaseRoot . '/vendor-scoped/autoload.php', true)
    . '; echo "ok";';
$autoloadSmokeOutput = run(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($autoloadSmokeCode), $releaseRoot, true);
if ('ok' !== $autoloadSmokeOutput) {
    throw new RuntimeException('Scoped release Composer autoloader collision smoke test failed.');
}
$smokeCode = 'require ' . var_export($releaseRoot . '/vendor-scoped/autoload.php', true) . '; echo strlen((new Mpadmin2fa\\Security\\TotpService())->generateSecret());';
$smokeOutput = run(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($smokeCode), $releaseRoot, true);
if ('32' !== $smokeOutput) {
    throw new RuntimeException('Scoped release TOTP smoke test failed.');
}

$sbom = run('composer show --locked --format=json --no-dev', $stageRoot, true);
file_put_contents($releaseRoot . '/SBOM.json', $sbom . PHP_EOL);

$forbidden = [
    'BaconQrCode\\',
    'DASPRiD\\',
    'Defuse\\Crypto\\',
    'ParagonIE\\',
    'PragmaRX\\Google2FA\\',
];
foreach (phpFiles($releaseRoot) as $file) {
    if (str_contains(str_replace('\\\\', '/', $file), '/vendor-scoped/composer/')
        || str_ends_with(str_replace('\\\\', '/', $file), '/vendor-scoped/autoload.php')
    ) {
        continue;
    }
    $contents = file_get_contents($file);
    foreach ($forbidden as $namespace) {
        if (str_contains($contents, $namespace) && !str_contains($contents, 'MpAdmin2Fa\\Mpadmin2faVendor\\' . $namespace)) {
            throw new RuntimeException(sprintf('Unscoped namespace %s remains in %s.', $namespace, $file));
        }
    }
}

$checksums = [];
foreach (allFiles($releaseRoot) as $file) {
    $relative = substr($file, strlen($releaseRoot) + 1);
    if ('SHA256SUMS' === $relative) {
        continue;
    }
    $checksums[] = hash_file('sha256', $file) . '  ' . str_replace('\\', '/', $relative);
}
sort($checksums);
file_put_contents($releaseRoot . '/SHA256SUMS', implode(PHP_EOL, $checksums) . PHP_EOL);

fwrite(STDOUT, 'Scoped release created at ' . $releaseRoot . PHP_EOL);

function assertModuleNamespacesPreserved(string $releaseRoot): void
{
    $scopedModuleNamespace = 'namespace MpAdmin2Fa\Mpadmin2faVendor\Mpadmin2fa';
    foreach (phpFiles($releaseRoot . '/src') as $file) {
        if (str_contains(file_get_contents($file), $scopedModuleNamespace)) {
            throw new RuntimeException('The release builder scoped a module-owned class: ' . $file);
        }
    }

    $entrypoint = file_get_contents($releaseRoot . '/mpadmin2fa.php');
    if (preg_match('/namespace\s+MpAdmin2Fa\\\\Mpadmin2faVendor\s*;/', $entrypoint)) {
        throw new RuntimeException('The release builder scoped the global PrestaShop module class.');
    }

    foreach (['Access', 'Configuration', 'Context', 'Db', 'Dispatcher', 'Language', 'Mail', 'Module', 'Profile', 'Tab', 'Tools', 'Validate'] as $legacyClass) {
        $scopedLegacyClass = sprintf('MpAdmin2Fa%1$sMpadmin2faVendor%1$s%s', chr(92), $legacyClass);
        foreach (phpFiles($releaseRoot . '/src') as $file) {
            if (str_contains(file_get_contents($file), $scopedLegacyClass)) {
                throw new RuntimeException('The release builder scoped PrestaShop class ' . $legacyClass . ': ' . $file);
            }
        }
    }
}

function writePrestaShopAutoloadBridge(string $releaseRoot): void
{
    $vendorDirectory = $releaseRoot . '/vendor';
    if (!mkdir($vendorDirectory, 0775, true) && !is_dir($vendorDirectory)) {
        throw new RuntimeException('Unable to create the PrestaShop autoload bridge directory.');
    }

    $contents = <<<'PHP'
<?php

declare(strict_types=1);

return require dirname(__DIR__) . '/vendor-scoped/autoload.php';
PHP;
    file_put_contents($vendorDirectory . '/autoload.php', $contents . PHP_EOL);
}

function writeScopedAutoload(string $vendorDirectory): void
{
    $autoloadPath = $vendorDirectory . '/autoload.php';
    $contents = file_get_contents($autoloadPath);
    $registration = <<<'PHP'
$loader = $1;
$loader->setPsr4('BaconQrCode\\', []);
$loader->setPsr4('DASPRiD\\Enum\\', []);
$loader->setPsr4('Defuse\\Crypto\\', []);
$loader->setPsr4('ParagonIE\\ConstantTime\\', []);
$loader->setPsr4('PragmaRX\\Google2FA\\', []);
$loader->setPsr4('MpAdmin2Fa\\Mpadmin2faVendor\\BaconQrCode\\', __DIR__ . '/bacon/bacon-qr-code/src');
$loader->setPsr4('MpAdmin2Fa\\Mpadmin2faVendor\\DASPRiD\\Enum\\', __DIR__ . '/dasprid/enum/src');
$loader->setPsr4('MpAdmin2Fa\\Mpadmin2faVendor\\Defuse\\Crypto\\', __DIR__ . '/defuse/php-encryption/src');
$loader->setPsr4('MpAdmin2Fa\\Mpadmin2faVendor\\ParagonIE\\ConstantTime\\', __DIR__ . '/paragonie/constant_time_encoding/src');
$loader->setPsr4('MpAdmin2Fa\\Mpadmin2faVendor\\PragmaRX\\Google2FA\\', __DIR__ . '/pragmarx/google2fa/src');
return $loader;
PHP;
    $rewritten = preg_replace_callback(
        '/return (ComposerAutoloaderInit[A-Za-z0-9_]+::getLoader\\(\\));/',
        static fn (array $matches): string => str_replace('$1', $matches[1], $registration),
        $contents,
        1,
        $count
    );
    if (1 !== $count || !is_string($rewritten)) {
        throw new RuntimeException('Unable to augment the scoped Composer autoloader.');
    }
    file_put_contents($autoloadPath, $rewritten);
}

function run(string $command, string $workingDirectory, bool $capture = false): string
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start: ' . $command);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (0 !== $exitCode) {
        throw new RuntimeException(trim($stderr . PHP_EOL . $stdout));
    }
    if (!$capture) {
        fwrite(STDOUT, $stdout);
        fwrite(STDERR, $stderr);
    }

    return trim($stdout);
}

function copyTree(string $source, string $destination, array $excludedNames = [], bool $overwritePhp = true): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($excludedNames): bool {
                return !in_array($file->getFilename(), $excludedNames, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }

            continue;
        }
        if (!$overwritePhp && str_ends_with($target, '.php') && is_file($target)) {
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy($item->getPathname(), $target);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function phpFiles(string $root): array
{
    return array_values(array_filter(allFiles($root), static fn (string $file): bool => str_ends_with($file, '.php')));
}

function allFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }

    return $files;
}
