#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$ref = $argv[1] ?? 'HEAD';

if (str_starts_with($ref, '-')) {
    fwrite(STDERR, "Aufruf: php bin/compare.php [Stand]\n");
    exit(2);
}

foreach (['opt/packagist/autoload.php' => 'composer install', '.cache/sources' => 'php bin/build.php'] as $path => $remedy) {
    if (!file_exists($root . '/' . $path)) {
        fwrite(STDERR, "{$path} fehlt. Bitte zuerst „{$remedy}“ ausführen.\n");
        exit(2);
    }
}

$work = $root . '/tmp/compare';
$base = $work . '/base';

removeTree($work);

if (!mkdir($base, 0o777, true) && !is_dir($base)) {
    fwrite(STDERR, "Ordner {$base} lässt sich nicht anlegen.\n");
    exit(2);
}

run('git -C ' . escapeshellarg($root) . ' archive ' . escapeshellarg($ref) . ' | tar -x -C ' . escapeshellarg($base), "Stand „{$ref}“ lässt sich nicht auslesen.");

foreach (['opt', '.cache'] as $shared) {
    if (!symlink($root . '/' . $shared, $base . '/' . $shared)) {
        fwrite(STDERR, "Verknüpfung für {$shared} lässt sich nicht anlegen.\n");
        exit(2);
    }
}

echo "Baue Stand „{$ref}“ …\n";
run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/bin/build.php') . ' --local-only 2>&1', 'Der Bau des Vergleichsstands ist fehlgeschlagen.');

echo "Baue Arbeitsstand …\n";
run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/build.php') . ' --local-only 2>&1', 'Der Bau des Arbeitsstands ist fehlgeschlagen.');

$before = hashes($base . '/tmp/public');
$after = hashes($root . '/tmp/public');

$added = array_keys(array_diff_key($after, $before));
$removed = array_keys(array_diff_key($before, $after));
$changed = array_keys(array_filter($after, fn(string $hash, string $file): bool => isset($before[$file]) && $before[$file] !== $hash, ARRAY_FILTER_USE_BOTH));

removeTree($work);

if ($added === [] && $removed === [] && $changed === []) {
    echo 'Identisch: alle ' . count($after) . " Dateien sind bitgleich.\n";
    exit(0);
}

printf("Unterschiede: %d neu, %d entfernt, %d geändert (von %d Dateien).\n", count($added), count($removed), count($changed), count($after));

foreach (['Neu' => $added, 'Entfernt' => $removed, 'Geändert' => $changed] as $title => $files) {
    foreach (array_slice($files, 0, 20) as $file) {
        echo "  {$title}: {$file}\n";
    }

    if (count($files) > 20) {
        echo "  {$title}: … und " . (count($files) - 20) . " weitere\n";
    }
}

exit(1);

function run(string $command, string $error): void
{
    passthru($command, $code);

    if ($code !== 0) {
        fwrite(STDERR, $error . "\n");
        exit(2);
    }
}

/** @return array<string, string> */
function hashes(string $directory): array
{
    $result = [];

    if (!is_dir($directory)) {
        return $result;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile()) {
            $result[substr($file->getPathname(), strlen($directory) + 1)] = sha1_file($file->getPathname()) ?: '';
        }
    }

    ksort($result);

    return $result;
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }

    rmdir($path);
}
