<?php

declare(strict_types=1);

const MODULE_NAME = 'mpadmin2fa';

$root = dirname(__DIR__);
$tag = $argv[1] ?? getenv('GITHUB_REF_NAME') ?: '';
if (!preg_match('/^v(?<version>[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?)$/', $tag, $matches)) {
    throw new RuntimeException('Release tag must use v1.2.3 or v1.2.3-rc.1.');
}
$version = $matches['version'];
if ($version !== moduleVersion($root . '/mpadmin2fa.php')) {
    throw new RuntimeException(sprintf('Tag %s does not match the module version.', $tag));
}

$composer = getenv('COMPOSER_BINARY') ?: 'composer';
run([$composer, 'validate', '--strict'], $root);
run([$composer, 'install', '--prefer-dist', '--no-interaction', '--no-progress'], $root);
run([$composer, 'test'], $root);
run([PHP_BINARY, $root . '/tools/build-scoped.php'], $root);

$releaseRoot = $root . '/build/' . MODULE_NAME;
if (!is_dir($releaseRoot) || $version !== moduleVersion($releaseRoot . '/mpadmin2fa.php')) {
    throw new RuntimeException('The scoped release is missing or has the wrong version.');
}

$distRoot = $root . '/dist';
resetDist($distRoot, $root);
$archiveName = MODULE_NAME . '-' . $tag . '.zip';
$archivePath = $distRoot . '/' . $archiveName;
createArchive($releaseRoot, $archivePath, commitTimestamp($root));
verifyArchive($archivePath);

$checksum = hash_file('sha256', $archivePath);
if (false === $checksum) {
    throw new RuntimeException('Unable to hash the release archive.');
}
$checksumPath = $archivePath . '.sha256';
file_put_contents($checksumPath, $checksum . '  ' . $archiveName . PHP_EOL);
fwrite(STDOUT, sprintf("Release ready:\n- %s\n- %s\n", $archivePath, $checksumPath));

function moduleVersion(string $path): string
{
    $source = file_get_contents($path);
    if (false === $source
        || !preg_match('/\$this->version\s*=\s*[\'"](?<version>[^\'"]+)[\'"]\s*;/', $source, $matches)
    ) {
        throw new RuntimeException(sprintf('Unable to read module version from %s.', $path));
    }

    return $matches['version'];
}

/** @param list<string> $command */
function run(array $command, string $cwd): void
{
    fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to start %s.', $command[0]));
    }
    $exitCode = proc_close($process);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('%s failed with exit code %d.', $command[0], $exitCode));
    }
}

/** @param list<string> $command */
function capture(array $command, string $cwd): string
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to start %s.', $command[0]));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (0 !== $exitCode || false === $stdout) {
        throw new RuntimeException(trim((string) $stderr) ?: sprintf('%s failed.', $command[0]));
    }

    return trim($stdout);
}

function commitTimestamp(string $root): int
{
    $configured = getenv('SOURCE_DATE_EPOCH');
    if (false !== $configured && preg_match('/^[0-9]+$/', $configured)) {
        return (int) $configured;
    }
    $timestamp = capture(['git', 'log', '-1', '--format=%ct'], $root);
    if (!preg_match('/^[0-9]+$/', $timestamp)) {
        throw new RuntimeException('Unable to determine the commit timestamp.');
    }

    return (int) $timestamp;
}

function resetDist(string $path, string $root): void
{
    if (dirname($path) !== $root || 'dist' !== basename($path)) {
        throw new RuntimeException('Unsafe distribution directory.');
    }
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create dist/.');
    }
}

function createArchive(string $releaseRoot, string $archivePath, int $timestamp): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required.');
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException(sprintf('Release file is a symlink: %s', $item->getPathname()));
        }
        if ($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }
    sort($files, SORT_STRING);

    $zip = new ZipArchive();
    if (true !== $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new RuntimeException('Unable to create the release archive.');
    }
    $zip->addEmptyDir(MODULE_NAME);
    setMetadata($zip, MODULE_NAME . '/', $timestamp, 0040755);
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file, strlen($releaseRoot) + 1));
        $entry = MODULE_NAME . '/' . $relative;
        if (!$zip->addFile($file, $entry)) {
            throw new RuntimeException(sprintf('Unable to add %s.', $relative));
        }
        setMetadata($zip, $entry, $timestamp, 0100644);
    }
    if (!$zip->close()) {
        throw new RuntimeException('Unable to finalize the release archive.');
    }
}

function setMetadata(ZipArchive $zip, string $entry, int $timestamp, int $mode): void
{
    $zip->setMtimeName($entry, $timestamp);
    $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, $mode << 16);
}

function verifyArchive(string $archivePath): void
{
    $zip = new ZipArchive();
    if (true !== $zip->open($archivePath)) {
        throw new RuntimeException('Unable to reopen the release archive.');
    }
    $entries = [];
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        $entry = $zip->getNameIndex($index);
        if (false === $entry || 0 !== strpos($entry, MODULE_NAME . '/')) {
            throw new RuntimeException('Every archive entry must be inside mpadmin2fa/.');
        }
        $relative = substr($entry, strlen(MODULE_NAME) + 1);
        foreach (['.git', '.github/', '.phpunit.result.cache', 'build/', 'dist/', 'docs/', 'documentation/', 'tests/', 'tools/', 'vendor/'] as $forbidden) {
            if (0 === strpos($relative, $forbidden)) {
                throw new RuntimeException(sprintf('Development-only path found: %s', $entry));
            }
        }
        $entries[$entry] = true;
    }
    foreach ([
        MODULE_NAME . '/mpadmin2fa.php',
        MODULE_NAME . '/vendor-scoped/autoload.php',
        MODULE_NAME . '/SBOM.json',
        MODULE_NAME . '/SHA256SUMS',
    ] as $required) {
        if (!isset($entries[$required])) {
            throw new RuntimeException(sprintf('Required release file missing: %s', $required));
        }
    }
    $zip->close();
}
