#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$info = in_array('--info', $args, true);
$args = array_values(array_diff($args, ['--info']));
$ref = $args[0] ?? 'HEAD';

if (str_starts_with($ref, '-')) {
    fwrite(STDERR, "Aufruf: php bin/compare.php [--info] [Stand]\n");
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

if (!symlink($root . '/.cache', $base . '/.cache')) {
    fwrite(STDERR, "Verknüpfung für .cache lässt sich nicht anlegen.\n");
    exit(2);
}

if (!mkdir($base . '/opt/packagist', 0o775, true)) {
    fwrite(STDERR, "Ordner opt/packagist lässt sich nicht anlegen.\n");
    exit(2);
}

foreach (scandir($root . '/opt/packagist') ?: [] as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }

    $from = $root . '/opt/packagist/' . $name;
    $to = $base . '/opt/packagist/' . $name;

    if ($name === 'composer') {
        copyTree($from, $to);
    } elseif ($name === 'autoload.php') {
        copy($from, $to);
    } elseif (!symlink($from, $to)) {
        fwrite(STDERR, "Verknüpfung für {$name} lässt sich nicht anlegen.\n");
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

if ($info) {
    $summary = sprintf(
        'Die Ausgabe weicht von „%s“ ab: %d neu, %d entfernt, %d geändert. Bei Änderungen an Code oder Inhalten ist das erwartet.',
        $ref,
        count($added),
        count($removed),
        count($changed),
    );
    echo ($_SERVER['GITHUB_ACTIONS'] ?? '') === 'true' ? "::notice title=Ausgabe verändert::{$summary}\n" : $summary . "\n";
    exit(0);
}

exit(1);

function run(string $command, string $error): void
{
    $code = 0;
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

    /** @var SplFileInfo $file */
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

function copyTree(string $from, string $to): void
{
    mkdir($to, 0o775, true);

    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        is_dir($from . '/' . $entry) ? copyTree($from . '/' . $entry, $to . '/' . $entry) : copy($from . '/' . $entry, $to . '/' . $entry);
    }
}
