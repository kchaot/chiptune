#!/usr/bin/env php
<?php

declare(strict_types=1);

use Chiptune\Site\CodeDocs;

$root = dirname(__DIR__);

if (!is_file($root . '/opt/packagist/autoload.php')) {
    fwrite(STDERR, "opt/packagist/ fehlt. Bitte zuerst „composer install“ ausführen.\n");

    exit(2);
}

require $root . '/opt/packagist/autoload.php';

$docs = new CodeDocs($root);

if (in_array('--check', $argv, true)) {
    $missing = $docs->missing();

    foreach ($missing as $name) {
        fwrite(STDERR, "Keine Beschreibung: {$name}\n");
    }

    if ($missing === []) {
        $count = array_sum(array_map(count(...), $docs->areas()));
        echo "Alle {$count} Klassen sind beschrieben.\n";
    }

    exit($missing === [] ? 0 : 1);
}

$target = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => !str_starts_with($arg, '--')))[0] ?? null;

if ($target === null) {
    echo $docs->markdown();

    exit(0);
}

if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o775, true)) {
    fwrite(STDERR, 'Ordner konnte nicht angelegt werden: ' . dirname($target) . "\n");

    exit(1);
}

if (file_put_contents($target, $docs->markdown()) === false) {
    fwrite(STDERR, "Datei konnte nicht geschrieben werden: {$target}\n");

    exit(1);
}
